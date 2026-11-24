<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\Authentication\AuthenticationException;
use Neo\Security\Authentication\AuthManager;
use Neo\Security\Authorization\AuthorizationException;
use Neo\Security\Authorization\Permission\PermissionRegistrar;

/**
 * Нужна роль (middleware `role`). Достаточно ЛЮБОЙ из перечисленных:
 *
 *   Route::get('/panel', ...)->middleware('role:admin');
 *   Route::get('/panel', ...)->middleware('role:admin|moderator');
 *   Route::get('/api/panel', ...)->middleware('role:admin,api');    // вторым параметром охранник
 *
 * Гость: 401 (для браузера редирект на вход). Вошёл, но роли нет: 403.
 * Суперадмин (auth.authorization.super_admin_role) проходит всегда.
 */
class RoleMiddleware
{
    public function __construct(protected readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next, string $roles, ?string $guard = null): Response
    {
        $user = $this->auth->guard($guard)->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        if (!$this->allowed($user, $roles)) {
            throw new AuthorizationException('User does not have the right roles.');
        }

        return $next($request);
    }

    protected function allowed(object $user, string $roles): bool
    {
        if (!method_exists($user, 'hasRole')) {
            return false;
        }

        $superAdmin = PermissionRegistrar::superAdminRole();

        return $user->hasRole($roles) || ($superAdmin !== null && $user->hasRole($superAdmin));
    }
}
