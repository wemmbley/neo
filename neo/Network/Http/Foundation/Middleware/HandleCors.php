<?php

declare(strict_types=1);

namespace Neo\Network\Http\Foundation\Middleware;

use Closure;
use Neo\Information\Structures\Str;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * CORS (настройки: app/Config/Cors.php). Работает как глобальное middleware, ДО маршрутизации,
 * поэтому preflight-запросы (OPTIONS) отвечают корректно, даже если такого маршрута нет.
 *
 * Правила безопасности:
 *  - заголовок Origin сравнивается с белым списком точно (или по шаблону из allowed_origins_patterns);
 *  - при supports_credentials=true символ `*` в allowed_origins НЕ работает: отражать любой
 *    Origin вместе с куками значит разрешить любому сайту читать ответы от имени пользователя;
 *  - Vary: Origin выставляется всегда, иначе кэш отдаст ответ с чужими CORS-заголовками.
 */
final class HandleCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->config();

        if (!$this->pathMatches($request, $config['paths'])) {
            return $next($request);
        }

        $origin = $request->headers()->get('Origin');

        if ($this->isPreflight($request)) {
            $response = new Response('', 204);
            $this->vary($response);

            return $origin !== null && $this->originAllowed($origin, $config)
                ? $this->addPreflightHeaders($response, $request, $origin, $config)
                : $response;
        }

        $response = $next($request);
        $this->vary($response);

        if ($origin !== null && $this->originAllowed($origin, $config)) {
            $this->addActualHeaders($response, $origin, $config);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = (array) config('cors', []);

        return [
            'paths' => (array) ($config['paths'] ?? []),
            'allowed_methods' => array_map('strtoupper', (array) ($config['allowed_methods'] ?? ['*'])),
            'allowed_origins' => (array) ($config['allowed_origins'] ?? []),
            'allowed_origins_patterns' => (array) ($config['allowed_origins_patterns'] ?? []),
            'allowed_headers' => (array) ($config['allowed_headers'] ?? ['*']),
            'exposed_headers' => (array) ($config['exposed_headers'] ?? []),
            'max_age' => (int) ($config['max_age'] ?? 0),
            'supports_credentials' => (bool) ($config['supports_credentials'] ?? false),
        ];
    }

    /** @param list<string> $paths */
    private function pathMatches(Request $request, array $paths): bool
    {
        foreach ($paths as $path) {
            $path = trim((string) $path, '/');

            if ($request->is($path === '' ? '/' : $path)) {
                return true;
            }
        }

        return false;
    }

    private function isPreflight(Request $request): bool
    {
        return $request->getRealMethod() === 'OPTIONS' && $request->headers()->has('Access-Control-Request-Method');
    }

    /** @param array<string, mixed> $config */
    private function originAllowed(string $origin, array $config): bool
    {
        if ($origin === 'null') {
            return false;
        }

        $origin = strtolower(rtrim($origin, '/'));

        foreach ($config['allowed_origins'] as $allowed) {
            if ($allowed === '*') {
                if (!$config['supports_credentials']) {
                    return true;
                }

                continue;
            }

            if (strtolower(rtrim((string) $allowed, '/')) === $origin) {
                return true;
            }
        }

        foreach ($config['allowed_origins_patterns'] as $pattern) {
            if (@preg_match((string) $pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $config */
    private function allowOriginValue(string $origin, array $config): string
    {
        # Без кук `*` допустим и проще для кэша; с куками всегда конкретный Origin.
        if (!$config['supports_credentials'] && in_array('*', $config['allowed_origins'], true)) {
            return '*';
        }

        return $origin;
    }

    /** @param array<string, mixed> $config */
    private function addPreflightHeaders(Response $response, Request $request, string $origin, array $config): Response
    {
        $response->header('Access-Control-Allow-Origin', $this->allowOriginValue($origin, $config));

        $method = strtoupper((string) $request->headers()->get('Access-Control-Request-Method'));
        $methods = $config['allowed_methods'];

        if (!in_array('*', $methods, true) && !in_array($method, $methods, true)) {
            $response->headers()->remove('Access-Control-Allow-Origin');

            return $response;
        }

        $response->header('Access-Control-Allow-Methods', in_array('*', $methods, true) ? $method : implode(', ', $methods));

        $requested = (string) $request->headers()->get('Access-Control-Request-Headers');

        if ($requested !== '') {
            $names = array_filter(array_map('trim', explode(',', $requested)), static fn (string $h): bool => preg_match('/^[A-Za-z0-9-]+$/', $h) === 1);

            if (in_array('*', $config['allowed_headers'], true)) {
                $allowed = $names;
            } else {
                $allowedLower = array_map('strtolower', $config['allowed_headers']);
                $allowed = array_filter($names, static fn (string $h): bool => in_array(strtolower($h), $allowedLower, true));
            }

            if ($allowed !== []) {
                $response->header('Access-Control-Allow-Headers', implode(', ', $allowed));
            }
        }

        if ($config['max_age'] > 0) {
            $response->header('Access-Control-Max-Age', (string) $config['max_age']);
        }

        if ($config['supports_credentials']) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /** @param array<string, mixed> $config */
    private function addActualHeaders(Response $response, string $origin, array $config): void
    {
        $response->header('Access-Control-Allow-Origin', $this->allowOriginValue($origin, $config));

        if ($config['exposed_headers'] !== []) {
            $response->header('Access-Control-Expose-Headers', implode(', ', $config['exposed_headers']));
        }

        if ($config['supports_credentials']) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
    }

    private function vary(Response $response): void
    {
        if (!$response->headers()->contains('Vary', 'Origin')) {
            $response->header('Vary', 'Origin', false);
        }
    }
}
