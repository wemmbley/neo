<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use BackedEnum;
use InvalidArgumentException;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Request;
use Neo\Network\Session\SessionManager;
use Neo\Security\Crypt\CryptManager;

/**
 * Генерация URL (фасад `URL::`, хелперы url(), route(), asset()).
 *
 * Корень сайта берётся из текущего запроса (с учётом доверенных прокси) или из настройки
 * `http.url.root`, если она задана. Для публичных сайтов задайте trusted_hosts в Security.php:
 * иначе подделанный Host попадёт в ссылки (например, в письма «сбросить пароль»).
 */
final class UrlGenerator
{
    public function __construct(
        private readonly Router $router,
        private readonly RequestContextContract $context,
        private readonly ServiceContainer $container,
    ) {}

    // ------------------------------------------------------------------
    // Адреса
    // ------------------------------------------------------------------

    /** @param array<int|string, mixed> $extra */
    public function to(string $path, array $extra = [], ?bool $secure = null): string
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }

        $root = $this->root($secure);
        $tail = implode('/', array_map('rawurlencode', array_map('strval', $extra)));
        $path = trim($path . '/' . $tail, '/');

        return $root . ($path === '' ? '' : '/' . $path) . ($path === '' ? '/' : '');
    }

    public function secure(string $path, array $extra = []): string
    {
        return $this->to($path, $extra, true);
    }

    public function asset(string $path, ?bool $secure = null): string
    {
        return $this->isValidUrl($path) ? $path : $this->root($secure) . '/' . ltrim($path, '/');
    }

    public function current(): string
    {
        return $this->request()?->url() ?? $this->root();
    }

    public function full(): string
    {
        return $this->request()?->fullUrl() ?? $this->root();
    }

    /**
     * Адрес, с которого пришёл пользователь. Заголовок Referer принимается только с этого
     * же хоста, иначе `back()` стал бы открытым редиректом на чужой сайт.
     */
    public function previous(string|false $fallback = false): string
    {
        $request = $this->request();

        if ($request !== null) {
            $referer = $request->headers()->get('Referer');

            if ($referer !== null && $this->isSameHost($referer, $request)) {
                return $referer;
            }

            if ($request->hasSession()) {
                $stored = $request->session()->get('_previous.url');

                if (is_string($stored) && $this->isSameHost($stored, $request)) {
                    return $stored;
                }
            }
        }

        return $fallback !== false ? $this->to($fallback) : $this->to('/');
    }

    // ------------------------------------------------------------------
    // Именованные маршруты
    // ------------------------------------------------------------------

    /**
     * @param array<string|int, mixed> $parameters именованные подставляются в `{param}`, остальные уходят в query
     */
    public function route(string $name, array $parameters = [], bool $absolute = true): string
    {
        $route = $this->router->getByName($name)
            ?? throw new InvalidArgumentException("Маршрут [{$name}] не определён.");

        return $this->toRoute($route, $parameters, $absolute);
    }

    /** @param array<string|int, mixed> $parameters */
    public function toRoute(Route $route, array $parameters, bool $absolute = true): string
    {
        $uri = $route->uri();
        $wheres = $route->wheres();
        $positional = array_values(array_filter($parameters, static fn ($k): bool => is_int($k), ARRAY_FILTER_USE_KEY));
        $named = array_filter($parameters, static fn ($k): bool => is_string($k), ARRAY_FILTER_USE_KEY);
        $used = [];

        $uri = (string) preg_replace_callback(
            '/(\/?)\{([A-Za-z_]\w*)(?::\w+)?(\?)?\}/',
            function (array $m) use (&$named, &$positional, &$used, $wheres, $route): string {
                [, $slash, $name] = $m;
                $optional = ($m[3] ?? '') === '?';

                if (array_key_exists($name, $named)) {
                    $value = $named[$name];
                    $used[$name] = true;
                } elseif ($positional !== []) {
                    $value = array_shift($positional);
                } else {
                    $value = null;
                }

                if ($value === null || $value === '') {
                    if ($optional) {
                        return '';
                    }

                    throw new InvalidArgumentException("Для маршрута [{$route->getName()}] не передан параметр [{$name}].");
                }

                $encoded = rawurlencode($this->stringify($value, $route->bindingField($name)));

                # В параметрах с шаблоном `.*` слэши остаются слэшами.
                if (in_array($wheres[$name] ?? '', ['.*', '.+'], true)) {
                    $encoded = str_replace('%2F', '/', $encoded);
                }

                return $slash . $encoded;
            },
            $uri,
        );

        $query = array_diff_key($named, $used);
        $uri = ($uri === '' ? '/' : $uri) . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        if (!$absolute) {
            return $uri;
        }

        $domain = $route->getDomain();

        if ($domain !== null) {
            $host = (string) preg_replace_callback(
                '/\{(\w+)\??\}/',
                static fn (array $m): string => rawurlencode((string) ($parameters[$m[1]] ?? '')),
                $domain,
            );

            return ($this->request()?->getScheme() ?? 'http') . '://' . $host . $uri;
        }

        return $this->root() . $uri;
    }

    // ------------------------------------------------------------------
    // Подписанные ссылки
    // ------------------------------------------------------------------

    /**
     * Ссылка с подписью: параметры нельзя изменить, не сломав подпись (middleware `signed`).
     *
     * @param array<string|int, mixed> $parameters
     */
    public function signedRoute(string $name, array $parameters = [], \DateTimeInterface|\DateInterval|int|null $expiration = null, bool $absolute = true): string
    {
        if ($expiration !== null) {
            $parameters['expires'] = $this->availableAt($expiration);
        }

        ksort($parameters);

        $url = $this->route($name, $parameters, $absolute);

        return $url . (str_contains($url, '?') ? '&' : '?') . 'signature=' . $this->signature($url);
    }

    public function temporarySignedRoute(string $name, \DateTimeInterface|\DateInterval|int $expiration, array $parameters = [], bool $absolute = true): string
    {
        return $this->signedRoute($name, $parameters, $expiration, $absolute);
    }

    public function hasValidSignature(Request $request, bool $absolute = true): bool
    {
        return $this->hasCorrectSignature($request, $absolute) && $this->signatureHasNotExpired($request);
    }

    public function hasCorrectSignature(Request $request, bool $absolute = true): bool
    {
        $given = $request->query('signature');

        if (!is_string($given) || $given === '') {
            return false;
        }

        $query = $request->query();
        unset($query['signature']);

        $base = $absolute ? $request->url() : $request->getPathInfo();
        $url = $base . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return hash_equals($this->signature($url), $given);
    }

    public function signatureHasNotExpired(Request $request): bool
    {
        $expires = $request->query('expires');

        return $expires === null || (is_numeric($expires) && (int) $expires >= time());
    }

    private function signature(string $url): string
    {
        return $this->container->make(CryptManager::class)->hmac($url, 'url-signature');
    }

    private function availableAt(\DateTimeInterface|\DateInterval|int $expiration): int
    {
        return match (true) {
            $expiration instanceof \DateTimeInterface => $expiration->getTimestamp(),
            $expiration instanceof \DateInterval => (new \DateTimeImmutable())->add($expiration)->getTimestamp(),
            default => time() + $expiration,
        };
    }

    // ------------------------------------------------------------------

    public function isValidUrl(string $path): bool
    {
        if (preg_match('~^(#|//|https?://|(mailto|tel|sms):)~i', $path) === 1) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }

    private function root(?bool $secure = null): string
    {
        $forced = config('http.url.root');

        if (is_string($forced) && $forced !== '') {
            $root = rtrim($forced, '/');
        } else {
            $request = $this->request();
            $root = $request?->root() ?? 'http://localhost';
        }

        $forceHttps = $secure ?? (bool) config('http.url.force_https', false);

        if ($forceHttps) {
            return (string) preg_replace('#^http://#i', 'https://', $root);
        }

        return $root;
    }

    private function request(): ?Request
    {
        if (!$this->context->isEntered()) {
            return null;
        }

        $request = $this->context->request();

        return $request instanceof Request ? $request : null;
    }

    private function isSameHost(string $url, Request $request): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) === 0
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function stringify(mixed $value, ?string $field): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_object($value) && method_exists($value, 'getRouteKey') => (string) $value->getRouteKey(),
            is_object($value) && $field !== null && isset($value->{$field}) => (string) $value->{$field},
            is_object($value) && method_exists($value, 'getKey') => (string) $value->getKey(),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) || $value instanceof \Stringable => (string) $value,
            default => throw new InvalidArgumentException('Недопустимый тип параметра URL: ' . get_debug_type($value) . '.'),
        };
    }
}
