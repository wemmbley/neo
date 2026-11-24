<?php

declare(strict_types=1);

namespace Neo\Network\Http\Foundation\Middleware;

use Closure;
use Neo\Information\Structures\Str;
use Neo\Network\Cookie\CookieValue;
use Neo\Network\Http\Exceptions\TokenMismatchException;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\Crypt\CryptManager;
use Neo\Security\Csrf\CsrfTokenManager;

/**
 * Защита от CSRF для небезопасных методов (POST, PUT, PATCH, DELETE).
 *
 * Токен ищется в `_token` (поле формы / JSON), заголовке `X-CSRF-TOKEN` или `X-XSRF-TOKEN`
 * (значение куки XSRF-TOKEN, которую читают JS-клиенты вроде axios). Без совпавшего токена
 * запрос получает 419. Должно стоять ПОСЛЕ StartSession: токен привязан к сессии.
 *
 * Вторая линия: если браузер прислал заголовок Origin с другим хостом, запрос отклоняется
 * ещё до сверки токена (`security.csrf.verify_origin`).
 *
 * Исключения: `security.csrf.except`, шаблоны путей вроде `webhooks/*`.
 */
class VerifyCsrfToken
{
    public const string XSRF_COOKIE = 'XSRF-TOKEN';

    /** @var list<string> дополнительные исключения в подклассе */
    protected array $except = [];

    public function __construct(
        protected readonly CsrfTokenManager $csrf,
        protected readonly CryptManager $crypt,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isReading() || $this->inExceptArray($request)) {
            return $this->addCookieToResponse($request, $next($request));
        }

        $this->verifyOrigin($request);

        if (!$this->tokensMatch($request)) {
            throw new TokenMismatchException();
        }

        return $this->addCookieToResponse($request, $next($request));
    }

    protected function inExceptArray(Request $request): bool
    {
        $patterns = [...$this->except, ...(array) config('security.csrf.except', [])];

        foreach ($patterns as $pattern) {
            $pattern = $pattern === '/' ? '/' : trim((string) $pattern, '/');

            if ($request->fullUrlIs($pattern) || $request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function verifyOrigin(Request $request): void
    {
        if (!config('security.csrf.verify_origin', true)) {
            return;
        }

        $origin = $request->headers()->get('Origin');

        if ($origin === null || $origin === 'null') {
            return;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        if (!is_string($host)) {
            throw new TokenMismatchException('Cross-origin request blocked.');
        }

        $allowed = array_map('strtolower', (array) config('security.csrf.allowed_origins', []));

        if (strcasecmp($host, $request->getHost()) !== 0 && !Str::is($allowed, strtolower($host))) {
            throw new TokenMismatchException('Cross-origin request blocked.');
        }
    }

    protected function tokensMatch(Request $request): bool
    {
        $token = $this->getTokenFromRequest($request);

        return is_string($token) && $this->csrf->verify($token);
    }

    protected function getTokenFromRequest(Request $request): ?string
    {
        $token = $request->input('_token');
        $token = is_string($token) && $token !== '' ? $token : $request->headers()->get('X-CSRF-TOKEN');

        if (($token === null || $token === '') && ($header = $request->headers()->get('X-XSRF-TOKEN')) !== null) {
            # Значение пришло из зашифрованной куки: расшифровываем с тем же контекстом, что и EncryptCookies.
            $token = $this->crypt->decrypt($header, 'cookie|' . self::XSRF_COOKIE);
        }

        return $token === '' ? null : $token;
    }

    /** Кука для JS-клиентов: читается скриптом (HttpOnly=false), поэтому токен недолговечен и привязан к сессии. */
    protected function addCookieToResponse(Request $request, Response $response): Response
    {
        if (!config('security.csrf.xsrf_cookie', true) || !$request->hasSession()) {
            return $response;
        }

        $ttl = $this->csrf->ttl();

        return $response->withCookie(new CookieValue(
            self::XSRF_COOKIE,
            $this->csrf->token(),
            time() + $ttl,
            (string) config('session.path', '/'),
            config('session.domain'),
            (bool) (config('session.secure') ?? $request->isSecure()),
            false,
            (string) config('session.same_site', 'lax'),
        ));
    }
}
