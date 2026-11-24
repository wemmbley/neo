<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing\Middleware;

use Closure;
use LogicException;
use Neo\Network\Http\Exceptions\HttpResponseException;
use Neo\Network\Http\Exceptions\ThrottleRequestsException;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\RateLimiter\Limit;
use Neo\Security\RateLimiter\RateLimiter;

/**
 * Ограничение частоты запросов (middleware `throttle`).
 *
 *   throttle:60,1      60 запросов в минуту
 *   throttle:api       именованный лимитер: RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->ip()))
 *
 * Добавляет заголовки X-RateLimit-Limit / X-RateLimit-Remaining, при превышении
 * отвечает 429 с Retry-After. Незнакомое имя лимитера даёт ошибку, а не «без лимита».
 * Без явного `by()` ключом служит IP клиента (с учётом доверенных прокси).
 */
class ThrottleRequests
{
    public function __construct(protected readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, int|string $maxAttempts = 60, int|float|string $decayMinutes = 1, string $prefix = ''): Response
    {
        if (is_string($maxAttempts) && !is_numeric($maxAttempts)) {
            $callback = $this->limiter->limiter($maxAttempts)
                ?? throw new LogicException("Лимитер [{$maxAttempts}] не определён: добавьте RateLimiter::for('{$maxAttempts}', ...).");

            $result = $callback($request);

            if ($result instanceof Response) {
                return $result;
            }

            $limits = [];

            foreach (is_array($result) ? $result : [$result] as $limit) {
                if ($limit instanceof Limit && !$limit->isUnlimited()) {
                    $key = $limit->key !== '' ? $limit->key : (string) $request->ip();
                    $limits[] = [md5($maxAttempts . $key), $limit->maxAttempts, $limit->decaySeconds, $limit->responseCallback];
                }
            }

            return $this->handleRequest($request, $next, $limits);
        }

        return $this->handleRequest($request, $next, [[
            $prefix . $this->signature($request),
            (int) $maxAttempts,
            (int) round(60 * (float) $decayMinutes),
            null,
        ]]);
    }

    /**
     * @param list<array{0: string, 1: int, 2: int, 3: ?Closure}> $limits
     */
    protected function handleRequest(Request $request, Closure $next, array $limits): Response
    {
        foreach ($limits as [$key, $max, $decay, $callback]) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw $this->buildException($request, $key, $max, $callback);
            }

            $this->limiter->hit($key, $decay);
        }

        $response = $next($request);

        if ($limits !== []) {
            [$key, $max] = $limits[array_key_last($limits)];

            $response->header('X-RateLimit-Limit', $max)
                ->header('X-RateLimit-Remaining', $this->limiter->remaining($key, $max));
        }

        return $response;
    }

    protected function buildException(Request $request, string $key, int $max, ?Closure $callback): \Throwable
    {
        $retryAfter = $this->limiter->availableIn($key);

        $headers = [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Reset' => (string) (time() + $retryAfter),
            'X-RateLimit-Limit' => (string) $max,
            'X-RateLimit-Remaining' => '0',
        ];

        if ($callback !== null) {
            $response = $callback($request, $headers);

            if ($response instanceof Response) {
                return new HttpResponseException($response);
            }
        }

        return new ThrottleRequestsException('Too Many Attempts.', $headers);
    }

    /** Ключ по умолчанию: пользователь (если есть) или IP, плюс маршрут, чтобы лимиты разных маршрутов не смешивались. */
    protected function signature(Request $request): string
    {
        $user = $request->user();

        $who = is_object($user) && method_exists($user, 'getAuthIdentifier')
            ? 'user:' . $user->getAuthIdentifier()
            : 'ip:' . $request->ip();

        return sha1(implode('|', [$request->getHost(), $request->route()?->uri() ?? $request->path(), $who]));
    }
}
