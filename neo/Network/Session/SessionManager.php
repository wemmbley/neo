<?php

declare(strict_types=1);

namespace Neo\Network\Session;

use Neo\Network\Cookie\CookieManager;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Session\Contracts\SessionDriverContract;
use Neo\Security\Crypt\CryptManager;
use RuntimeException;

#
# Session API. Данные сессии хранятся в request context, не в свойствах singleton-менеджера.
#
final class SessionManager
{
    private const CONTEXT_KEY = 'neo.session.state';

    public function __construct(
        private readonly array $config,
        private readonly SessionDriverContract $driver,
        private readonly CookieManager $cookies,
        private readonly RequestContextContract $context,
        private readonly ?CryptManager $crypt = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->data();
        return $this->readPath($data, $key, $default);
    }

    public function put(string|array $key, mixed $value = null): void
    {
        $state = $this->state();
        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            $this->writePath($state['data'], (string) $name, $item);
        }
        $state['dirty'] = true;
        $this->context->set(self::CONTEXT_KEY, $state);
    }

    public function set(string|array $key, mixed $value = null): void { $this->put($key, $value); }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return $this->get($key, $sentinel) !== $sentinel;
    }

    public function exists(string $key): bool { return $this->has($key); }
    public function missing(string $key): bool { return !$this->has($key); }

    public function forget(string|array $keys): void
    {
        $state = $this->state();
        foreach ((array) $keys as $key) $this->forgetPath($state['data'], (string) $key);
        $state['dirty'] = true;
        $this->context->set(self::CONTEXT_KEY, $state);
    }

    public function flush(): void
    {
        $state = $this->state();
        $state['data'] = [];
        $state['dirty'] = true;
        $this->context->set(self::CONTEXT_KEY, $state);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function push(string $key, mixed $value): void
    {
        $items = $this->get($key, []);
        if (!is_array($items)) $items = [];
        $items[] = $value;
        $this->put($key, $items);
    }

    public function increment(string $key, int $amount = 1): int
    {
        $value = (int) $this->get($key, 0) + $amount;
        $this->put($key, $value);
        return $value;
    }

    public function decrement(string $key, int $amount = 1): int { return $this->increment($key, -$amount); }

    public function flash(string|array $key, mixed $value = null): void
    {
        $this->put($key, $value);
        $state = $this->state();
        $keys = is_array($key) ? array_keys($key) : [$key];
        $state['data']['_flash']['new'] = array_values(array_unique([
            ...($state['data']['_flash']['new'] ?? []), ...$keys,
        ]));
        $this->context->set(self::CONTEXT_KEY, $state);
    }

    public function getFlash(string $key, mixed $default = null): mixed { return $this->get($key, $default); }

    public function reflash(): void
    {
        $state = $this->state();
        $state['data']['_flash']['new'] = array_values(array_unique([
            ...($state['data']['_flash']['new'] ?? []), ...($state['data']['_flash']['old'] ?? []),
        ]));
        $state['data']['_flash']['old'] = [];
        $state['dirty'] = true;
        $this->context->set(self::CONTEXT_KEY, $state);
    }

    public function keep(string|array $keys): void
    {
        $state = $this->state();
        $keep = (array) $keys;
        $state['data']['_flash']['new'] = array_values(array_unique([
            ...($state['data']['_flash']['new'] ?? []), ...array_intersect($state['data']['_flash']['old'] ?? [], $keep),
        ]));
        $state['data']['_flash']['old'] = array_values(array_diff($state['data']['_flash']['old'] ?? [], $keep));
        $state['dirty'] = true;
        $this->context->set(self::CONTEXT_KEY, $state);
    }

    public function getId(): string { return $this->state()['id']; }
    public function isStarted(): bool { return $this->context->get(self::CONTEXT_KEY) !== null; }

    public function start(): void { $this->state(); }

    public function save(): void
    {
        $state = $this->state();
        foreach (($state['data']['_flash']['old'] ?? []) as $key) {
            $this->forgetPath($state['data'], (string) $key);
        }
        $state['data']['_flash']['old'] = $state['data']['_flash']['new'] ?? [];
        $state['data']['_flash']['new'] = [];
        $this->driver->write($state['id'], $this->encodeData($state['data']), time());
        $lottery = (array) ($this->config['lottery'] ?? [2, 100]);
        $chance = (int) ($lottery[0] ?? 0);
        $odds = max(1, (int) ($lottery[1] ?? 100));
        if ($chance > 0 && random_int(1, $odds) <= $chance) {
            $this->driver->gc((int) ($this->config['lifetime'] ?? 120) * 60);
        }
        $this->cookies->set((string) ($this->config['cookie'] ?? 'neo_session'), $state['id'], (bool) ($this->config['expire_on_close'] ?? false) ? 0 : (int) ($this->config['lifetime'] ?? 120), [
            'path' => $this->config['path'] ?? '/', 'domain' => $this->config['domain'] ?? null,
            'secure' => $this->config['secure'] ?? false, 'http_only' => $this->config['http_only'] ?? true,
            'same_site' => $this->config['same_site'] ?? 'lax', 'partitioned' => $this->config['partitioned'] ?? false,
        ]);
        $state['dirty'] = false;
        $this->context->set(self::CONTEXT_KEY, $state);
    }

    public function regenerate(bool $destroy = false): bool
    {
        $state = $this->state();
        $old = $state['id'];
        $state['id'] = bin2hex(random_bytes(40));
        $state['dirty'] = true;
        $this->context->set(self::CONTEXT_KEY, $state);
        if ($destroy) $this->driver->destroy($old);
        return true;
    }

    public function invalidate(): void
    {
        $this->flush();
        $this->regenerate(true);
    }

    public function destroy(): void
    {
        $state = $this->state();
        $this->driver->destroy($state['id']);
        $this->cookies->forget((string) ($this->config['cookie'] ?? 'neo_session'));
        $this->context->forget(self::CONTEXT_KEY);
    }

    public function all(): array { return $this->data(); }
    public function count(): int { return count($this->data()); }
    public function driver(): SessionDriverContract { return $this->driver; }

    private function data(): array { return $this->state()['data']; }

    private function state(): array
    {
        $state = $this->context->get(self::CONTEXT_KEY);
        if ($state !== null) return $state;

        $cookie = $this->cookies->get((string) ($this->config['cookie'] ?? 'neo_session'));
        $candidate = is_string($cookie) && preg_match('/^[a-f0-9]{80}$/', $cookie) ? $cookie : null;
        $stored = $candidate === null ? null : $this->driver->read($candidate);
        $id = $stored === null ? bin2hex(random_bytes(40)) : $candidate;
        $data = $this->decodeData($stored ?? []);
        $data['_flash'] ??= ['new' => [], 'old' => []];
        $state = ['id' => $id, 'data' => $data, 'dirty' => false];
        $this->context->set(self::CONTEXT_KEY, $state);
        return $state;
    }

    private function encodeData(array $data): array
    {
        if (!(bool) ($this->config['encrypt'] ?? false)) return $data;
        if ($this->crypt === null) throw new RuntimeException('Session encryption is enabled, but CryptManager is unavailable.');
        return ['__neo_encrypted' => $this->crypt->encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'session')];
    }

    private function decodeData(array $data): array
    {
        if (!array_key_exists('__neo_encrypted', $data)) return $data;
        if ($this->crypt === null) throw new RuntimeException('Encrypted session data requires CryptManager.');
        $plain = $this->crypt->decrypt((string) $data['__neo_encrypted'], 'session');
        if ($plain === null) return [];
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readPath(array $data, string $key, mixed $default): mixed
    {
        $value = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }
        return $value;
    }

    private function writePath(array &$data, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$data;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) $target[$segment] = [];
            $target = &$target[$segment];
        }
        $target = $value;
    }

    private function forgetPath(array &$data, string $key): void
    {
        $segments = explode('.', $key);
        $target = &$data;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) return;
            $target = &$target[$segment];
        }
        unset($target[$last]);
    }
}
