<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use InvalidArgumentException;
use JsonSerializable;
use Neo\Network\Cookie\CookieValue;
use Neo\Network\Http\Contracts\ResponseContract;
use Stringable;

/**
 * HTTP-ответ. Сам ничего не отправляет: отправкой занимается адаптер среды
 * (SapiEmitter для FPM/php -S, SwooleServer для Swoole), поэтому один и тот же
 * Response работает в любом рантайме.
 */
class Response implements ResponseContract
{
    public const array PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information',
        204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required',
        412 => 'Precondition Failed', 413 => 'Content Too Large', 414 => 'URI Too Long',
        415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable', 418 => "I'm a teapot",
        419 => 'Page Expired', 422 => 'Unprocessable Content', 423 => 'Locked',
        429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported',
    ];

    protected HeaderBag $headers;

    protected string $content = '';

    protected int $statusCode = 200;

    protected string $statusText = 'OK';

    protected string $version = '1.1';

    /** @var array<string, CookieValue> */
    protected array $cookies = [];

    /** @param array<string, string|list<string>> $headers */
    public function __construct(mixed $content = '', int $status = 200, array $headers = [])
    {
        $this->headers = new HeaderBag($headers, strict: true);
        $this->setContent($content);
        $this->setStatusCode($status);
    }

    // ------------------------------------------------------------------
    // Тело
    // ------------------------------------------------------------------

    /**
     * Массивы и JsonSerializable превращаются в JSON с нужным Content-Type, остальное в строку.
     */
    public function setContent(mixed $content): static
    {
        if ($content === null || $content === false) {
            $content = '';
        }

        if (is_array($content) || ($content instanceof JsonSerializable) || (is_object($content) && method_exists($content, 'toArray') && !$content instanceof Stringable)) {
            $data = is_array($content) ? $content : (method_exists($content, 'toArray') ? $content->toArray() : $content);
            $this->headers->set('Content-Type', 'application/json');
            $content = json_encode($data, JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_THROW_ON_ERROR);
        }

        if (!is_scalar($content) && !$content instanceof Stringable) {
            throw new InvalidArgumentException('Содержимое ответа должно быть строкой, получено ' . get_debug_type($content) . '.');
        }

        $this->content = (string) $content;

        return $this;
    }

    public function content(): string
    {
        return $this->getContent();
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Потоковая отправка тела: адаптер передаёт функцию записи (echo или Swoole write()).
     * Обычный ответ пишет всё тело одним куском.
     *
     * @param callable(string): void $write
     */
    public function streamTo(callable $write): void
    {
        $content = $this->getContent();

        if ($content !== '') {
            $write($content);
        }
    }

    /** true = тело нельзя целиком держать в памяти или оно генерируется по частям. */
    public function isStreamed(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------
    // Статус
    // ------------------------------------------------------------------

    public function setStatusCode(int $code, ?string $text = null): static
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException("Недопустимый HTTP-статус [{$code}].");
        }

        $this->statusCode = $code;
        $this->statusText = $text ?? (self::PHRASES[$code] ?? 'Unknown Status');

        return $this;
    }

    public function status(): int
    {
        return $this->statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function statusText(): string
    {
        return $this->statusText;
    }

    public function protocolVersion(): string
    {
        return $this->version;
    }

    // ------------------------------------------------------------------
    // Заголовки
    // ------------------------------------------------------------------

    public function headers(): HeaderBag
    {
        return $this->headers;
    }

    public function header(string $key, string|int|array $values, bool $replace = true): static
    {
        $this->headers->set($key, $values, $replace);

        return $this;
    }

    /** @param array<string, string|list<string>> $headers */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $key => $value) {
            $this->headers->set((string) $key, $value);
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // Cookies
    // ------------------------------------------------------------------

    /**
     * `$response->cookie('name', 'value', $minutes)`: быстрый способ. Параметры по умолчанию
     * для path/domain/secure/sameSite берутся из app/Config/Cookie.php только в CookieManager;
     * здесь значения безопасные: HttpOnly, SameSite=Lax.
     */
    public function cookie(
        string|CookieValue $name,
        string $value = '',
        int $minutes = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        ?string $sameSite = 'lax',
    ): static {
        if ($name instanceof CookieValue) {
            return $this->withCookie($name);
        }

        return $this->withCookie(new CookieValue(
            $name,
            $value,
            $minutes === 0 ? null : time() + $minutes * 60,
            $path,
            $domain,
            $secure,
            $httpOnly,
            $sameSite,
        ));
    }

    public function withCookie(CookieValue $cookie): static
    {
        $this->cookies[$cookie->key()] = $cookie;

        return $this;
    }

    /** Просит браузер удалить куку. */
    public function withoutCookie(string $name, string $path = '/', ?string $domain = null): static
    {
        return $this->withCookie(new CookieValue($name, '', 1, $path, $domain));
    }

    /** @return array<string, CookieValue> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** @param array<string, CookieValue> $cookies */
    public function setCookies(array $cookies): static
    {
        $this->cookies = $cookies;

        return $this;
    }

    // ------------------------------------------------------------------
    // Классификация
    // ------------------------------------------------------------------

    public function isInformational(): bool { return $this->statusCode >= 100 && $this->statusCode < 200; }

    public function isSuccessful(): bool { return $this->statusCode >= 200 && $this->statusCode < 300; }

    public function isRedirection(): bool { return $this->statusCode >= 300 && $this->statusCode < 400; }

    public function isClientError(): bool { return $this->statusCode >= 400 && $this->statusCode < 500; }

    public function isServerError(): bool { return $this->statusCode >= 500 && $this->statusCode < 600; }

    public function isOk(): bool { return $this->statusCode === 200; }

    public function isForbidden(): bool { return $this->statusCode === 403; }

    public function isNotFound(): bool { return $this->statusCode === 404; }

    public function isEmpty(): bool { return in_array($this->statusCode, [204, 304], true); }

    public function isRedirect(?string $location = null): bool
    {
        return in_array($this->statusCode, [201, 301, 302, 303, 307, 308], true)
            && ($location === null || $location === $this->headers->get('Location'));
    }

    // ------------------------------------------------------------------
    // Подготовка к отправке
    // ------------------------------------------------------------------

    /**
     * Доводит ответ до корректного HTTP: Content-Type, Content-Length, пустое тело для
     * HEAD/204/304/1xx. Вызывается ядром перед отправкой.
     */
    public function prepare(Request $request): static
    {
        $headers = $this->headers;

        if ($this->isInformational() || $this->isEmpty()) {
            $this->content = '';
            $headers->remove('Content-Type');
            $headers->remove('Content-Length');
            $headers->remove('Transfer-Encoding');
        } else {
            if (!$headers->has('Content-Type')) {
                $headers->set('Content-Type', 'text/html; charset=UTF-8');
            } elseif (stripos((string) $headers->get('Content-Type'), 'text/') === 0 && stripos((string) $headers->get('Content-Type'), 'charset') === false) {
                $headers->set('Content-Type', $headers->get('Content-Type') . '; charset=UTF-8');
            }

            if (!$this->isStreamed() && !$headers->has('Transfer-Encoding')) {
                $headers->set('Content-Length', (string) strlen($this->getContent()));
            }
        }

        if ($request->method() === 'HEAD') {
            # Длина остаётся как у GET, тела нет.
            $this->content = '';
            $this->headToken = true;
        }

        if (!$headers->has('Cache-Control')) {
            $headers->set('Cache-Control', 'no-cache, private');
        }

        return $this;
    }

    /** true после prepare() для HEAD: адаптер не должен писать тело. */
    protected bool $headToken = false;

    public function isHeadResponse(): bool
    {
        return $this->headToken;
    }

    public function __toString(): string
    {
        $lines = ["HTTP/{$this->version} {$this->statusCode} {$this->statusText}"];

        foreach ($this->headers->toArray() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "{$name}: {$value}";
            }
        }

        foreach ($this->cookies as $cookie) {
            $lines[] = 'Set-Cookie: ' . $cookie->toHeader();
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $this->getContent();
    }
}
