<?php

declare(strict_types=1);

namespace Neo\Security\RateLimiter;

use Closure;

/**
 * Лимит запросов: `Limit::perMinute(60)->by($request->ip())`.
 */
final class Limit
{
    public function __construct(
        public readonly string $key = '',
        public readonly int $maxAttempts = 60,
        public readonly int $decaySeconds = 60,
        public readonly ?Closure $responseCallback = null,
    ) {}

    public static function perSecond(int $maxAttempts, int $decaySeconds = 1): self
    {
        return new self('', $maxAttempts, $decaySeconds);
    }

    public static function perMinute(int $maxAttempts): self
    {
        return new self('', $maxAttempts, 60);
    }

    public static function perMinutes(int $decayMinutes, int $maxAttempts): self
    {
        return new self('', $maxAttempts, 60 * $decayMinutes);
    }

    public static function perHour(int $maxAttempts, int $decayHours = 1): self
    {
        return new self('', $maxAttempts, 3600 * $decayHours);
    }

    public static function perDay(int $maxAttempts, int $decayDays = 1): self
    {
        return new self('', $maxAttempts, 86400 * $decayDays);
    }

    /** Без ограничений. */
    public static function none(): self
    {
        return new self('', PHP_INT_MAX, 0);
    }

    public function by(string|int|null $key): self
    {
        return new self((string) $key, $this->maxAttempts, $this->decaySeconds, $this->responseCallback);
    }

    /** Свой ответ при превышении: `fn (Request $r, array $headers) => response('Slow down', 429, $headers)`. */
    public function response(Closure $callback): self
    {
        return new self($this->key, $this->maxAttempts, $this->decaySeconds, $callback);
    }

    public function isUnlimited(): bool
    {
        return $this->maxAttempts === PHP_INT_MAX;
    }
}
