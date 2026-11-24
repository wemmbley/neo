<?php

declare(strict_types=1);

namespace Neo\Network\Cookie;

use Neo\Network\Http\Contracts\RequestContextContract;

#
# Cookie API. Все входящие данные читаются из Request, исходящие добавляются в Response.
#
final class CookieManager
{
    public function __construct(
        private readonly array $config,
        private readonly RequestContextContract $context,
    ) {}

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->context->request()->cookies()[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->context->request()->cookies());
    }

    public function all(): array
    {
        return $this->context->request()->cookies();
    }

    public function set(string $name, mixed $value, int $minutes = 0, array $options = []): void
    {
        $expires = $minutes === 0 ? null : time() + ($minutes * 60);
        $this->queue(new CookieValue(
            $name,
            (string) $value,
            $expires,
            (string) ($options['path'] ?? $this->config['path'] ?? '/'),
            $options['domain'] ?? $this->config['domain'] ?? null,
            (bool) ($options['secure'] ?? $this->config['secure'] ?? $this->context->request()->isSecure()),
            (bool) ($options['http_only'] ?? $this->config['http_only'] ?? true),
            $options['same_site'] ?? $this->config['same_site'] ?? 'lax',
            (bool) ($options['partitioned'] ?? $this->config['partitioned'] ?? false),
        ));
    }

    public function forever(string $name, mixed $value, array $options = []): void
    {
        $this->set($name, $value, 60 * 24 * 365 * 5, $options);
    }

    public function forget(string $name, array $options = []): void
    {
        $this->queue(new CookieValue(
            $name,
            '',
            1,
            (string) ($options['path'] ?? $this->config['path'] ?? '/'),
            $options['domain'] ?? $this->config['domain'] ?? null,
            (bool) ($options['secure'] ?? $this->config['secure'] ?? $this->context->request()->isSecure()),
            (bool) ($options['http_only'] ?? $this->config['http_only'] ?? true),
            $options['same_site'] ?? $this->config['same_site'] ?? 'lax',
            (bool) ($options['partitioned'] ?? $this->config['partitioned'] ?? false),
        ));
    }

    public function queue(CookieValue $cookie): void
    {
        $this->context->response()->withCookie($cookie);
    }
}
