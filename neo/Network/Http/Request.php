<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Closure;
use Neo\Information\Collection\Collection;
use Neo\Information\Persistence\Storage\Domain\UploadedFile;
use Neo\Information\Structures\Arr;
use Neo\Information\Structures\Str;
use Neo\Information\Validator\Validator;
use Neo\Network\Http\Contracts\RequestContract;
use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\Exceptions\SuspiciousOperationException;
use Neo\Network\Http\Routing\Route;
use Neo\Security\Proxy\IpMatcher;
use Neo\Network\Session\SessionManager;

/**
 * HTTP-запрос (API в стиле Laravel, без Symfony и PSR-7).
 *
 * Запрос НЕ хранит ничего глобального и не читает суперглобальные переменные сам,
 * кроме явных фабрик {@see capture()} (SAPI) и {@see create()} (тесты).
 * Swoole-адаптер собирает запрос конструктором из своих данных.
 *
 * Объект изменяемый (middleware правят вход, роутер кладёт сюда найденный маршрут),
 * но живёт ровно один запрос и не разделяется между корутинами.
 */
class Request implements RequestContract
{
    /** Методы, на которые разрешено подменять POST через _method / X-HTTP-Method-Override. */
    private const array SPOOFABLE_METHODS = ['PUT', 'PATCH', 'DELETE'];

    protected HeaderBag $headers;

    /** @var array<string, mixed>|null */
    protected ?array $json = null;

    /** Тело читается лениво из php://input, только если запрос создан через capture(). */
    protected bool $lazyBody = false;

    protected ?Route $route = null;

    protected ?Closure $userResolver = null;

    /** @var list<string> */
    protected array $trustedProxies = [];

    protected ?SessionManager $sessionStore = null;

    protected ?string $realMethod = null;

    protected ?string $method = null;

    /**
     * @param array<string, mixed>  $query      $_GET
     * @param array<string, mixed>  $request    $_POST (тело формы)
     * @param array<string, mixed>  $attributes служебные данные (в Laravel: route parameters и т. п.)
     * @param array<string, string> $cookies    $_COOKIE
     * @param array<string, mixed>  $files      $_FILES в «сыром» виде или уже UploadedFile
     * @param array<string, mixed>  $server     $_SERVER
     */
    public function __construct(
        protected array $query = [],
        protected array $request = [],
        protected array $attributes = [],
        protected array $cookies = [],
        protected array $files = [],
        protected array $server = [],
        protected ?string $content = null,
    ) {
        $this->headers = new HeaderBag($this->headersFromServer($this->server));
        $this->files = $this->normalizeFiles($this->files);
    }

    // ------------------------------------------------------------------
    // Фабрики
    // ------------------------------------------------------------------

    /** Запрос из суперглобальных переменных (классический SAPI: FPM, Apache, php -S). */
    public static function capture(): static
    {
        $server = $_SERVER;
        $request = new static($_GET, $_POST, [], $_COOKIE, $_FILES, $server, null);
        $request->lazyBody = true;

        # PUT/PATCH/DELETE с формой: PHP сам $_POST для них не заполняет.
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)
            && str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/x-www-form-urlencoded')
        ) {
            parse_str($request->getContent(), $parsed);
            $request->request = $parsed;
        }

        return $request;
    }

    /**
     * Запрос «руками»: тесты и внутренние подзапросы.
     *
     * @param array<string, mixed> $parameters query для GET/HEAD, иначе тело формы
     */
    public static function create(
        string $uri,
        string $method = 'GET',
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
    ): static {
        $components = parse_url($uri) ?: [];
        $scheme = $components['scheme'] ?? 'http';
        $host = $components['host'] ?? 'localhost';
        $port = $components['port'] ?? ($scheme === 'https' ? 443 : 80);

        $server = array_replace([
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'HTTP_HOST' => $host . (in_array($port, [80, 443], true) ? '' : ':' . $port),
            'HTTP_USER_AGENT' => 'Neo',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => strtoupper($method),
            'SCRIPT_NAME' => '',
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        ], $server);

        $query = [];
        parse_str((string) ($components['query'] ?? ''), $query);

        $post = [];
        if (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $query = array_replace($query, $parameters);
        } else {
            $post = $parameters;
        }

        $path = '/' . ltrim((string) ($components['path'] ?? '/'), '/');
        $server['REQUEST_URI'] = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $server['QUERY_STRING'] = http_build_query($query);

        return new static($query, $post, [], $cookies, $files, $server, $content ?? '');
    }

    /**
     * Копирует состояние запроса в экземпляр потомка (FormRequest).
     */
    public static function createFrom(self $from, ?self $to = null): static
    {
        $to ??= new static();

        foreach (get_object_vars($from) as $name => $value) {
            $to->{$name} = $value;
        }

        return $to;
    }

    // ------------------------------------------------------------------
    // Метод, URL, путь
    // ------------------------------------------------------------------

    public function getRealMethod(): string
    {
        return $this->realMethod ??= strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * Метод с учётом подмены. Подменяется только POST и только на PUT/PATCH/DELETE:
     * иначе форма смогла бы превратить запрос в GET в обход CSRF-проверки.
     */
    public function method(): string
    {
        if ($this->method !== null) {
            return $this->method;
        }

        $method = $this->getRealMethod();

        if ($method === 'POST') {
            $override = $this->headers->get('X-HTTP-Method-Override')
                ?? (is_string($this->request['_method'] ?? null) ? $this->request['_method'] : null);

            $override = strtoupper((string) $override);

            if (in_array($override, self::SPOOFABLE_METHODS, true)) {
                $method = $override;
            }
        }

        return $this->method = $method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isMethodSafe(): bool
    {
        return in_array($this->method(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
    }

    public function isReading(): bool
    {
        return in_array($this->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Путь запроса без query, декодированный, с одним слэшем между сегментами.
     * Именно его видят и роутер, и is(), и middleware: расхождений нет.
     * Всегда начинается с «/».
     */
    public function getPathInfo(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url('http://x' . (str_starts_with($uri, '/') ? '' : '/') . $uri, PHP_URL_PATH) ?? '/');

        $base = $this->basePath();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }

        $decoded = rawurldecode($path);

        if (str_contains($decoded, "\0")) {
            throw new SuspiciousOperationException('Недопустимый путь запроса.');
        }

        $decoded = (string) preg_replace('#/{2,}#', '/', $decoded);

        return $decoded === '' ? '/' : '/' . ltrim($decoded, '/');
    }

    /** Путь без начального и конечного слэша; `/` для корня (как в Laravel). */
    public function path(): string
    {
        $path = trim($this->getPathInfo(), '/');

        return $path === '' ? '/' : $path;
    }

    public function decodedPath(): string
    {
        return $this->path();
    }

    /** @return list<string> */
    public function segments(): array
    {
        return array_values(array_filter(explode('/', $this->path()), static fn (string $s): bool => $s !== ''));
    }

    public function segment(int $index, ?string $default = null): ?string
    {
        return $this->segments()[$index - 1] ?? $default;
    }

    /** Сопоставление пути с шаблонами: `$request->is('admin/*')`. */
    public function is(string ...$patterns): bool
    {
        $path = rawurldecode($this->path());

        foreach ($patterns as $pattern) {
            if (Str::is($pattern === '/' ? '/' : trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    public function fullUrlIs(string ...$patterns): bool
    {
        return Str::is($patterns, $this->fullUrl());
    }

    public function getQueryString(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '');
        $query = str_contains($uri, '?') ? (string) parse_url('http://x' . (str_starts_with($uri, '/') ? '' : '/') . $uri, PHP_URL_QUERY) : '';

        return $query !== '' ? $query : (string) ($this->server['QUERY_STRING'] ?? '');
    }

    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        if ($this->isFromTrustedProxy()) {
            $proto = strtolower(trim(explode(',', (string) $this->headers->get('X-Forwarded-Proto'))[0]));

            if ($proto !== '') {
                return $proto === 'https' || $proto === 'on';
            }
        }

        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off') || (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Имя хоста без порта, в нижнем регистре. Невалидный Host (в нём бывают переводы
     * строк, слэши, @) даёт SuspiciousOperationException: Host нельзя доверять без проверки.
     */
    public function getHost(): string
    {
        $host = null;

        if ($this->isFromTrustedProxy()) {
            $forwarded = trim(explode(',', (string) $this->headers->get('X-Forwarded-Host'))[0]);
            $host = $forwarded !== '' ? $forwarded : null;
        }

        $host ??= $this->headers->get('Host') ?? (string) ($this->server['SERVER_NAME'] ?? $this->server['SERVER_ADDR'] ?? '');

        # Порт отрезаем, IPv6 в скобках оставляем.
        $host = (string) preg_replace('/:\d+$/', '', trim(strtolower($host)));

        if ($host === '' || preg_match('/^(\[[a-f0-9:.]+\]|[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?)$/', $host) !== 1) {
            throw new SuspiciousOperationException('Недопустимый заголовок Host.');
        }

        return $host;
    }

    public function getPort(): int
    {
        if ($this->isFromTrustedProxy()) {
            $port = trim(explode(',', (string) $this->headers->get('X-Forwarded-Port'))[0]);

            if (ctype_digit($port) && $port !== '') {
                return (int) $port;
            }
        }

        $hostHeader = (string) $this->headers->get('Host');

        if (preg_match('/:(\d+)$/', $hostHeader, $m) === 1) {
            return (int) $m[1];
        }

        return (int) ($this->server['SERVER_PORT'] ?? ($this->isSecure() ? 443 : 80));
    }

    /** `host` или `host:port`, порт пишется только нестандартный. */
    public function getHttpHost(): string
    {
        $port = $this->getPort();
        $standard = ($this->getScheme() === 'https' && $port === 443) || ($this->getScheme() === 'http' && $port === 80);

        return $this->getHost() . ($standard ? '' : ':' . $port);
    }

    public function getSchemeAndHttpHost(): string
    {
        return $this->getScheme() . '://' . $this->getHttpHost();
    }

    public function root(): string
    {
        return rtrim($this->getSchemeAndHttpHost() . $this->basePath(), '/');
    }

    public function url(): string
    {
        return rtrim($this->root() . $this->getPathInfo(), '/') ?: $this->root() . '/';
    }

    public function fullUrl(): string
    {
        $query = $this->getQueryString();

        return $query !== '' ? $this->url() . '?' . $query : $this->url();
    }

    public function fullUrlWithQuery(array $query): string
    {
        $merged = array_merge($this->query, $query);

        return $this->url() . ($merged === [] ? '' : '?' . http_build_query($merged));
    }

    /** Подпапка приложения, если оно не в корне домена (по SCRIPT_NAME). */
    public function basePath(): string
    {
        $script = (string) ($this->server['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));

        return ($dir === '.' || $dir === '/' || $dir === '') ? '' : rtrim($dir, '/');
    }

    // ------------------------------------------------------------------
    // Клиент
    // ------------------------------------------------------------------

    /**
     * IP клиента. X-Forwarded-For учитывается ТОЛЬКО если запрос пришёл с доверенного
     * прокси ({@see setTrustedProxies()}), иначе любой клиент подделал бы свой адрес.
     * Адрес берётся справа налево, пропуская доверенные прокси.
     */
    public function ip(): ?string
    {
        return $this->ips()[0] ?? null;
    }

    /**
     * Адреса клиента от ближайшего к серверу: первый элемент дописал наш доверенный прокси,
     * остальное, что левее, уже пришло от клиента и может быть подделано.
     *
     * @return list<string>
     */
    public function ips(): array
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        $remote = filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';

        if ($remote === '' || !$this->isFromTrustedProxy()) {
            return $remote === '' ? [] : [$remote];
        }

        $forwarded = array_filter(array_map('trim', explode(',', (string) $this->headers->get('X-Forwarded-For'))), static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false);
        $chain = [...$forwarded, $remote];

        # Справа налево: отбрасываем доверенные прокси, первый чужой и есть клиент.
        $clients = [];
        foreach (array_reverse($chain) as $ip) {
            if (!IpMatcher::matches($ip, $this->trustedProxies)) {
                $clients[] = $ip;
            }
        }

        return $clients === [] ? [$chain[0]] : $clients;
    }

    /** @param list<string> $proxies IP, CIDR или `*` (доверять всем: только если сервер недоступен напрямую) */
    public function setTrustedProxies(array $proxies): static
    {
        $this->trustedProxies = array_values($proxies);

        return $this;
    }

    public function isFromTrustedProxy(): bool
    {
        return $this->trustedProxies !== []
            && IpMatcher::matches((string) ($this->server['REMOTE_ADDR'] ?? ''), $this->trustedProxies);
    }

    public function userAgent(): ?string
    {
        return $this->headers->get('User-Agent');
    }

    // ------------------------------------------------------------------
    // Заголовки, cookies, server
    // ------------------------------------------------------------------

    public function headers(): HeaderBag
    {
        return $this->headers;
    }

    public function header(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_map(static fn (array $values): string => $values[0] ?? '', $this->headers->toArray());
        }

        return $this->headers->get($key) ?? $default;
    }

    public function hasHeader(string $key): bool
    {
        return $this->headers->has($key);
    }

    public function bearerToken(): ?string
    {
        $header = (string) $this->headers->get('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->cookies : ($this->cookies[$key] ?? $default);
    }

    /** Подменяет входящие cookies (EncryptCookies). */
    public function replaceCookies(array $cookies): static
    {
        $this->cookies = $cookies;

        return $this;
    }

    public function server(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->server : ($this->server[$key] ?? $default);
    }

    public function getContent(): string
    {
        if ($this->content === null) {
            $this->content = $this->lazyBody ? (string) file_get_contents('php://input') : '';
        }

        return $this->content;
    }

    public function contentLength(): ?int
    {
        $length = $this->headers->get('Content-Length');

        return $length !== null && ctype_digit($length) ? (int) $length : null;
    }

    // ------------------------------------------------------------------
    // Content negotiation
    // ------------------------------------------------------------------

    public function isJson(): bool
    {
        $type = strtolower((string) $this->headers->get('Content-Type'));

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    public function ajax(): bool
    {
        return strtolower((string) $this->headers->get('X-Requested-With')) === 'xmlhttprequest';
    }

    /** @return list<string> типы из Accept по убыванию q */
    public function getAcceptableContentTypes(): array
    {
        $items = [];

        foreach (explode(',', (string) $this->headers->get('Accept')) as $i => $part) {
            $segments = explode(';', trim($part));
            $type = strtolower(trim($segments[0]));

            if ($type === '') {
                continue;
            }

            $q = 1.0;
            foreach (array_slice($segments, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }

            $items[] = [$type, $q, $i];
        }

        usort($items, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return array_map(static fn (array $item): string => $item[0], $items);
    }

    public function wantsJson(): bool
    {
        $first = $this->getAcceptableContentTypes()[0] ?? '';

        return str_contains($first, '/json') || str_contains($first, '+json');
    }

    public function acceptsAnyContentType(): bool
    {
        $types = $this->getAcceptableContentTypes();

        return $types === [] || $types[0] === '*/*' || $types[0] === '*';
    }

    public function expectsJson(): bool
    {
        return ($this->ajax() && $this->acceptsAnyContentType()) || $this->wantsJson();
    }

    /** @param string|list<string> $contentTypes */
    public function accepts(string|array $contentTypes): bool
    {
        $accepts = $this->getAcceptableContentTypes();

        if ($accepts === []) {
            return true;
        }

        foreach ((array) $contentTypes as $type) {
            $type = strtolower($type);

            foreach ($accepts as $accept) {
                if ($accept === '*/*' || $accept === '*' || $accept === $type
                    || (str_ends_with($accept, '/*') && str_starts_with($type, substr($accept, 0, -1)))
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function acceptsJson(): bool
    {
        return $this->accepts('application/json');
    }

    public function acceptsHtml(): bool
    {
        return $this->accepts('text/html');
    }

    // ------------------------------------------------------------------
    // Входные данные
    // ------------------------------------------------------------------

    /**
     * Все входные данные: тело + query + файлы.
     *
     * @return array<string, mixed>
     */
    public function all(array|string|null $keys = null): array
    {
        $input = array_replace_recursive($this->input(), $this->allFiles());

        if ($keys === null) {
            return $input;
        }

        $results = [];

        foreach ((array) $keys as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->getInputSource() + $this->query;

        return $key === null ? $data : Arr::get($data, $key, $default);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : Arr::get($this->query, $key, $default);
    }

    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->request : Arr::get($this->request, $key, $default);
    }

    /**
     * Тело JSON. Битый JSON даёт HTTP 400, а не тихий пустой массив:
     * иначе валидатор увидит «нет полей» и ошибка будет совсем не о том.
     *
     * @return array<string, mixed>|mixed
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->json === null) {
            $content = trim($this->getContent());

            if ($content === '') {
                $this->json = [];
            } else {
                try {
                    $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new HttpException(400, 'Malformed JSON.');
                }

                $this->json = is_array($decoded) ? $decoded : [];
            }
        }

        return $key === null ? $this->json : Arr::get($this->json, $key, $default);
    }

    /** @return array<string, mixed> */
    protected function getInputSource(): array
    {
        if ($this->isJson()) {
            return (array) $this->json();
        }

        return in_array($this->getRealMethod(), ['GET', 'HEAD'], true) ? $this->query : $this->request;
    }

    public function has(string|array $key): bool
    {
        $input = $this->all();

        foreach ((array) $key as $k) {
            if (!Arr::has($input, $k)) {
                return false;
            }
        }

        return true;
    }

    public function hasAny(string|array $keys): bool
    {
        return Arr::hasAny($this->all(), $keys);
    }

    public function filled(string|array $key): bool
    {
        foreach ((array) $key as $k) {
            if ($this->isEmptyValue($k)) {
                return false;
            }
        }

        return true;
    }

    public function anyFilled(string|array $keys): bool
    {
        foreach ((array) $keys as $k) {
            if (!$this->isEmptyValue($k)) {
                return true;
            }
        }

        return false;
    }

    public function missing(string|array $key): bool
    {
        return !$this->has($key);
    }

    protected function isEmptyValue(string $key): bool
    {
        $value = $this->input($key);

        return !is_bool($value) && !is_array($value) && trim((string) $value) === '';
    }

    public function only(string|array ...$keys): array
    {
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;
        $results = [];
        $input = $this->all();

        foreach ($keys as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    public function except(string|array ...$keys): array
    {
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;
        $results = $this->all();
        Arr::forget($results, $keys);

        return $results;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        return filter_var($this->input($key, $default), FILTER_VALIDATE_INT) !== false
            ? (int) $this->input($key, $default)
            : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function boolean(?string $key = null, bool $default = false): bool
    {
        return filter_var($this->input($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function collect(array|string|null $key = null): Collection
    {
        return new Collection(is_array($key) ? $this->only($key) : (array) $this->input($key, []));
    }

    /** Добавляет/заменяет входные данные. */
    public function merge(array $input): static
    {
        $this->setInputSource(array_replace($this->getInputSource(), $input));

        return $this;
    }

    public function mergeIfMissing(array $input): static
    {
        return $this->merge(Arr::only($input, array_filter(array_keys($input), fn ($key): bool => !$this->has((string) $key))));
    }

    public function replace(array $input): static
    {
        $this->setInputSource($input);

        return $this;
    }

    /**
     * Применяет функцию ко всем строкам входа (TrimStrings, ConvertEmptyStringsToNull).
     *
     * @param Closure(mixed, string|int): mixed $callback
     */
    public function transformInput(Closure $callback): static
    {
        $apply = static function (array $data) use ($callback, &$apply): array {
            foreach ($data as $key => $value) {
                $data[$key] = is_array($value) ? $apply($value) : $callback($value, $key);
            }

            return $data;
        };

        $this->query = $apply($this->query);
        $this->request = $apply($this->request);

        if ($this->isJson()) {
            $this->json = $apply((array) $this->json());
        }

        return $this;
    }

    protected function setInputSource(array $input): void
    {
        if ($this->isJson()) {
            $this->json = $input;

            return;
        }

        if (in_array($this->getRealMethod(), ['GET', 'HEAD'], true)) {
            $this->query = $input;
        } else {
            $this->request = $input;
        }
    }

    /**
     * Проверяет входные данные: `$request->validate(['email' => 'required|email'])`.
     *
     * @return array<string, mixed> только проверенные поля
     * @throws \Neo\Information\Validator\ValidationException
     */
    public function validate(array $rules, array $messages = [], array $attributes = []): array
    {
        return Validator::make($this->all(), $rules, $messages, $attributes)->validate();
    }

    // ------------------------------------------------------------------
    // Файлы
    // ------------------------------------------------------------------

    /** @return array<string, UploadedFile|array<mixed>> */
    public function allFiles(): array
    {
        return $this->files;
    }

    public function file(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->files : Arr::get($this->files, $key, $default);
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);

        return $file instanceof UploadedFile
            || (is_array($file) && array_filter($file, static fn ($f): bool => $f instanceof UploadedFile) !== []);
    }

    /**
     * Приводит $_FILES к дереву UploadedFile. Незагруженные поля (UPLOAD_ERR_NO_FILE)
     * пропускаются, как в Laravel.
     *
     * @return array<string, mixed>
     */
    protected function normalizeFiles(array $files): array
    {
        $result = [];

        foreach ($files as $key => $entry) {
            if ($entry instanceof UploadedFile || is_array($entry) && !isset($entry['tmp_name'])) {
                $result[$key] = $entry;
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $converted = $this->convertFileEntry($entry);

            if ($converted !== null) {
                $result[$key] = $converted;
            }
        }

        return $result;
    }

    private function convertFileEntry(array $entry): UploadedFile|array|null
    {
        if (!is_array($entry['tmp_name'] ?? null)) {
            if ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return null;
            }

            return UploadedFile::fromGlobals($entry);
        }

        $files = [];

        foreach (array_keys($entry['tmp_name']) as $i) {
            $child = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
                $child[$field] = $entry[$field][$i] ?? null;
            }

            $converted = $this->convertFileEntry($child);

            if ($converted !== null) {
                $files[$i] = $converted;
            }
        }

        return $files;
    }

    // ------------------------------------------------------------------
    // Маршрут, пользователь, сессия
    // ------------------------------------------------------------------

    /** Найденный маршрут (копия с параметрами этого запроса) или значение его параметра. */
    public function route(?string $param = null, mixed $default = null): mixed
    {
        if ($param === null) {
            return $this->route;
        }

        return $this->route?->parameter($param, $default) ?? ($default instanceof Closure ? $default() : $default);
    }

    public function setRoute(?Route $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function routeIs(string ...$patterns): bool
    {
        $name = $this->route?->getName();

        return $name !== null && Str::is($patterns, $name);
    }

    /** Authenticatable-пользователь: пока модуль Authentication не подключён, всегда null. */
    public function user(?string $guard = null): mixed
    {
        return $this->userResolver === null ? null : ($this->userResolver)($guard);
    }

    public function setUserResolver(?Closure $resolver): static
    {
        $this->userResolver = $resolver;

        return $this;
    }

    /** Сессия доступна, только если запрос прошёл через StartSession (группа middleware web). */
    public function hasSession(): bool
    {
        return $this->sessionStore !== null;
    }

    public function session(): SessionManager
    {
        return $this->sessionStore
            ?? throw new \RuntimeException('Сессия недоступна: маршрут должен проходить через middleware StartSession (группа web).');
    }

    public function setSession(SessionManager $session): static
    {
        $this->sessionStore = $session;

        return $this;
    }

    /** Отпечаток запроса для ограничения частоты: метод, хост, маршрут, IP. */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->method(),
            $this->getHost(),
            $this->route?->uri() ?? $this->path(),
            (string) $this->ip(),
        ]));
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) && $value !== '') {
                $headers[str_replace('_', '-', $key)] = (string) $value;
            }
        }

        return $headers;
    }
}
