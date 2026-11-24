<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\Authentication\AuthenticationException;
use Neo\Security\Authentication\AuthManager;

/**
 * Пускает только вошедших (middleware `auth`).
 *
 *   Route::get('/profile', ...)->middleware('auth');         // охранник по умолчанию
 *   Route::get('/api/me', ...)->middleware('auth:api');      // конкретный охранник
 *   Route::get('/x', ...)->middleware('auth:web,api');       // подойдёт любой из списка
 *
 * Не вошёл: исключение 401. Браузер получит редирект на `auth.redirect_to`
 * (если задан), JSON-клиент остаётся с 401.
 */
class Authenticate
{
    public function __construct(protected readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        foreach ($guards === [] ? [null] : $guards as $name) {
            if ($this->auth->guard($name)->check()) {
                $auth = $this->auth;
                $request->setUserResolver(static fn (?string $guard = null) => $auth->guard($guard ?? $name)->user());

                return $next($request);
            }
        }

        throw new AuthenticationException('Unauthenticated.', $guards);
    }
}
