<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Только администраторы (middleware `admin`): короткая запись `role:<auth.authorization.admin_role>`.
 *
 *   Route::prefix('admin')->middleware('admin')->group(...);
 */
class Admin extends RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $roles = '', ?string $guard = null): Response
    {
        return parent::handle($request, $next, (string) config('auth.authorization.admin_role', 'admin'), $guard ?: null);
    }
}
