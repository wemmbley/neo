<?php

declare(strict_types=1);

namespace Neo\Network\Cookie;

final readonly class CookieValue
{
    public function __construct(
        public string $name,
        public string $value,
        public ?int $expires = null,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = false,
        public bool $httpOnly = true,
        public ?string $sameSite = 'lax',
        public bool $partitioned = false,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Cookie name must not be empty.');
        }
        # Имя куки - token по RFC 6265: пробелы, «=», «;», CR/LF в нём недопустимы (header injection).
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Cookie name contains forbidden characters.');
        }
        if (preg_match('/[\x00-\x20;,\x7f]/', $path) === 1 || ($domain !== null && preg_match('/[\x00-\x20;,\x7f]/', $domain) === 1)) {
            throw new \InvalidArgumentException('Cookie path/domain contains forbidden characters.');
        }
        if (str_starts_with($name, '__Secure-') && !$secure) {
            throw new \InvalidArgumentException('Cookie with __Secure- prefix requires secure=true.');
        }
        if (str_starts_with($name, '__Host-') && (!$secure || $path !== '/' || $domain !== null)) {
            throw new \InvalidArgumentException('Cookie with __Host- prefix requires secure=true, path=/ and no domain.');
        }
        if ($sameSite !== null && !in_array(strtolower($sameSite), ['lax', 'strict', 'none'], true)) {
            throw new \InvalidArgumentException('Cookie same_site must be lax, strict, none or null.');
        }
        if ($partitioned && (!$secure || strtolower((string) $sameSite) !== 'none')) {
            throw new \InvalidArgumentException('Partitioned cookies require secure=true and same_site=none.');
        }
    }

    /** Та же кука с другим значением (для шифрования/подписи). */
    public function withValue(string $value): self
    {
        return new self(
            $this->name,
            $value,
            $this->expires,
            $this->path,
            $this->domain,
            $this->secure,
            $this->httpOnly,
            $this->sameSite,
            $this->partitioned,
        );
    }

    /** Ключ уникальности в очереди: браузер различает куки по имени, пути и домену. */
    public function key(): string
    {
        return $this->name . '|' . $this->path . '|' . ($this->domain ?? '');
    }

    /**
     * Значение заголовка Set-Cookie. Значение кодируется rawurlencode, поэтому
     * `;` и пробелы в нём не ломают заголовок. Expires и Max-Age ставятся оба.
     */
    public function toHeader(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value)];

        if ($this->expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $this->expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $this->expires - time());
        }

        $parts[] = 'Path=' . $this->path;

        $domain = $this->normalizedDomain();
        if ($domain !== null) {
            $parts[] = 'Domain=' . $domain;
        }

        $sameSite = $this->sameSite === null ? null : strtolower($this->sameSite);

        # Браузеры отвергают SameSite=None без Secure.
        if ($this->secure || $sameSite === 'none') {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($sameSite !== null) {
            $parts[] = 'SameSite=' . ucfirst($sameSite);
        }

        if ($this->partitioned) {
            $parts[] = 'Partitioned';
        }

        return implode('; ', $parts);
    }

    /**
     * Домен для атрибута Domain. Из конфигурации часто приходит APP_URL целиком
     * (`https://site.com`), а `localhost`, IP и имена без точки браузеры с Domain
     * отвергают, поэтому для них атрибут не пишется (host-only кука).
     */
    public function normalizedDomain(): ?string
    {
        if ($this->domain === null || $this->domain === '') {
            return null;
        }

        $domain = $this->domain;

        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }

        $domain = strtolower(ltrim($domain, '.'));

        if ($domain === '' || !str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $domain;
    }
}
