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
 * Нужна роль ИЛИ право (middleware `role_or_permission`): `role_or_permission:admin|post.update`.
 * Каждое имя проверяется и как роль, и как право.
 */
class RoleOrPermissionMiddleware
{
    public function __construct(protected readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next, string $rolesOrPermissions, ?string $guard = null): Response
    {
        $user = $this->auth->guard($guard)->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        if (!$this->allowed($user, $rolesOrPermissions)) {
            throw new AuthorizationException('User does not have the right roles or permissions.');
        }

        return $next($request);
    }

    protected function allowed(object $user, string $names): bool
    {
        $superAdmin = PermissionRegistrar::superAdminRole();

        if ($superAdmin !== null && method_exists($user, 'hasRole') && $user->hasRole($superAdmin)) {
            return true;
        }

        return (method_exists($user, 'hasRole') && $user->hasRole($names))
            || (method_exists($user, 'hasAnyPermission') && $user->hasAnyPermission($names));
    }
}
