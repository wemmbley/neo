<?php

declare(strict_types=1);

namespace Neo\Security\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\Headers\CspNonce;

/**
 * Защитные заголовки ответа (настройки: `security.headers`).
 *
 *   X-Content-Type-Options   запрет «угадывания» MIME (nosniff)
 *   X-Frame-Options          защита от clickjacking
 *   Referrer-Policy          что уходит в Referer на чужие сайты
 *   Strict-Transport-Security только для HTTPS-запросов
 *   Cross-Origin-Opener-Policy
 *   Content-Security-Policy  основная защита от XSS; {nonce} заменяется на nonce запроса
 *
 * Заголовки, уже выставленные приложением, не перезаписываются.
 */
final class SecurityHeaders
{
    public function __construct(private readonly CspNonce $nonce) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $config = (array) config('security.headers', []);

        if (($config['enabled'] ?? true) === false) {
            return $response;
        }

        $this->set($response, 'X-Content-Type-Options', $config['x_content_type_options'] ?? 'nosniff');
        $this->set($response, 'X-Frame-Options', $config['x_frame_options'] ?? 'SAMEORIGIN');
        $this->set($response, 'Referrer-Policy', $config['referrer_policy'] ?? 'strict-origin-when-cross-origin');
        $this->set($response, 'Cross-Origin-Opener-Policy', $config['cross_origin_opener_policy'] ?? 'same-origin');
        $this->set($response, 'Permissions-Policy', $config['permissions_policy'] ?? null);

        $hsts = $config['hsts'] ?? ['max_age' => 31536000, 'include_subdomains' => true, 'preload' => false];

        # HSTS по HTTP бессмысленен и игнорируется браузером; отправляем только по HTTPS.
        if ($hsts !== false && $request->isSecure()) {
            $this->set($response, 'Strict-Transport-Security', sprintf(
                'max-age=%d%s%s',
                (int) ($hsts['max_age'] ?? 31536000),
                ($hsts['include_subdomains'] ?? true) ? '; includeSubDomains' : '',
                ($hsts['preload'] ?? false) ? '; preload' : '',
            ));
        }

        if (is_string($csp = $config['csp'] ?? null) && $csp !== '') {
            $header = ($config['csp_report_only'] ?? false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $this->set($response, $header, str_replace('{nonce}', $this->nonce->get(), $csp));
        }

        return $response;
    }

    private function set(Response $response, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '' && !$response->headers()->has($name)) {
            $response->header($name, $value);
        }
    }
}
