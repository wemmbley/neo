<?php

declare(strict_types=1);

namespace Neo\Security\RateLimiter;

use Closure;
use Neo\Information\Cache\Managers\CacheManager;

/**
 * Ограничитель частоты на основе кэша (фасад RateLimiter::).
 *
 *   RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
 *
 * Счётчики живут в кэше. ВАЖНО: файловое хранилище кэша не атомарно между процессами
 * (при большом числе параллельных запросов счётчик может чуть недосчитать). Для строгих
 * лимитов (логин, SMS) используйте атомарное хранилище (Redis, БД).
 *
 * Именованные лимитеры регистрируются при загрузке и дальше только читаются.
 */
final class RateLimiter
{
    /** @var array<string, Closure> */
    private array $limiters = [];

    public function __construct(private readonly CacheManager $cache) {}

    public function for(string $name, Closure $callback): static
    {
        $this->limiters[$name] = $callback;

        return $this;
    }

    public function limiter(string $name): ?Closure
    {
        return $this->limiters[$name] ?? null;
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if ($this->attempts($key) >= $maxAttempts) {
            if ($this->cache->has($this->timerKey($key))) {
                return true;
            }

            $this->resetAttempts($key);
        }

        return false;
    }

    /** Засчитывает попытку; возвращает число попыток в текущем окне. */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        $this->cache->add($this->timerKey($key), time() + $decaySeconds, $decaySeconds);

        $added = $this->cache->add($this->counterKey($key), 0, $decaySeconds);
        $hits = (int) $this->cache->increment($this->counterKey($key));

        if (!$added && $hits === 1) {
            $this->cache->put($this->counterKey($key), 1, $decaySeconds);
        }

        return $hits;
    }

    public function attempts(string $key): int
    {
        return (int) $this->cache->get($this->counterKey($key), 0);
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    public function resetAttempts(string $key): bool
    {
        return $this->cache->forget($this->counterKey($key));
    }

    /** Полностью сбрасывает счётчик (например, после успешного входа). */
    public function clear(string $key): void
    {
        $this->resetAttempts($key);
        $this->cache->forget($this->timerKey($key));
    }

    /** Через сколько секунд лимит снова станет доступен. */
    public function availableIn(string $key): int
    {
        return max(0, (int) $this->cache->get($this->timerKey($key), 0) - time());
    }

    private function counterKey(string $key): string
    {
        return 'neo:rl:' . sha1($key);
    }

    private function timerKey(string $key): string
    {
        return $this->counterKey($key) . ':timer';
    }
}
