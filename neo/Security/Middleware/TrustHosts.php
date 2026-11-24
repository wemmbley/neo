<?php

declare(strict_types=1);

namespace Neo\Security\Middleware;

use Closure;
use Neo\Information\Structures\Str;
use Neo\Network\Http\Exceptions\SuspiciousOperationException;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Защита от подмены заголовка Host (отравление ссылок в письмах «сбросить пароль»,
 * кэш-отравление). Разрешённые хосты в `security.trusted_hosts`: точные имена,
 * маски `*.example.com` или регулярные выражения `#^(www\.)?example\.com$#`.
 *
 * Пустой список = проверка выключена. Для боевого сайта ЗАПОЛНИТЕ его.
 */
final class TrustHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = (array) config('security.trusted_hosts', []);

        if ($hosts !== [] && !$this->allowed($request->getHost(), $hosts)) {
            throw new SuspiciousOperationException('Недопустимый хост.');
        }

        return $next($request);
    }

    /** @param list<string> $patterns */
    private function allowed(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = strtolower((string) $pattern);

            if (str_starts_with($pattern, '#') && @preg_match($pattern, $host) === 1) {
                return true;
            }

            if (Str::is($pattern, $host)) {
                return true;
            }
        }

        return false;
    }
}
