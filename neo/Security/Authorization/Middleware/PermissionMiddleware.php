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
 * Нужно право (middleware `permission`). Достаточно ЛЮБОГО из перечисленных:
 *
 *   Route::delete('/posts/{id}', ...)->middleware('permission:post.delete');
 *   Route::get('/reports', ...)->middleware('permission:report.read|report.export');
 *   Route::get('/api/reports', ...)->middleware('permission:report.read,api');
 *
 * Гость: 401. Вошёл, но права нет: 403. Учитываются и прямые права, и права ролей,
 * и подстановочные знаки (`post.*`). Суперадмин проходит всегда.
 */
class PermissionMiddleware
{
    public function __construct(protected readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next, string $permissions, ?string $guard = null): Response
    {
        $user = $this->auth->guard($guard)->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        if (!$this->allowed($user, $permissions)) {
            throw new AuthorizationException('User does not have the right permissions.');
        }

        return $next($request);
    }

    protected function allowed(object $user, string $permissions): bool
    {
        $superAdmin = PermissionRegistrar::superAdminRole();

        if ($superAdmin !== null && method_exists($user, 'hasRole') && $user->hasRole($superAdmin)) {
            return true;
        }

        return method_exists($user, 'hasAnyPermission') && $user->hasAnyPermission($permissions);
    }
}
