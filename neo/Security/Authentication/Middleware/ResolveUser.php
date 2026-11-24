<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\Authentication\AuthManager;

/**
 * Учит `$request->user()` / `$request->user('api')` возвращать пользователя.
 * Подключается в группы web и api автоматически (см. AuthServiceProvider), после сессии.
 * Сам пользователя не грузит: запрос в БД случится только при первом обращении.
 */
class ResolveUser
{
    public function __construct(protected readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $auth = $this->auth;

        $request->setUserResolver(static fn (?string $guard = null) => $auth->guard($guard)->user());

        return $next($request);
    }
}
