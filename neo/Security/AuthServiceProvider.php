<?php

declare(strict_types=1);

namespace Neo\Security;

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Exceptions\Handler;
use Neo\Network\Http\Request;
use Neo\Network\Http\Routing\MiddlewareRegistry;
use Neo\Security\Authentication\AuthContext;
use Neo\Security\Authentication\AuthenticationException;
use Neo\Security\Authentication\AuthManager;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authentication\Middleware\Authenticate;
use Neo\Security\Authentication\Middleware\ResolveUser;
use Neo\Security\Authorization\Gate\GateManager;
use Neo\Security\Authorization\Middleware\Admin;
use Neo\Security\Authorization\Middleware\Authorize;
use Neo\Security\Authorization\Middleware\PermissionMiddleware;
use Neo\Security\Authorization\Middleware\RoleMiddleware;
use Neo\Security\Authorization\Middleware\RoleOrPermissionMiddleware;
use Neo\Security\Authorization\Permission\PermissionRegistrar;

/**
 * Аутентификация (Auth, охранники) и авторизация (Gate, политики, роли, права).
 * Настройки: app/Config/Auth.php. Регистрировать ПОСЛЕ Http, Session и ORM провайдеров.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Auth.php'), 'auth');

        $this->app->singleton(AuthContext::class, static fn (ServiceContainer $c): AuthContext => new AuthContext(
            $c->make(RequestContextContract::class),
        ));

        $this->app->singleton(AuthManager::class, static fn (ServiceContainer $c): AuthManager => new AuthManager(
            $c,
            (array) config('auth', []),
        ));

        $this->app->singleton(GateManager::class, static function (ServiceContainer $c): GateManager {
            $auth = $c->make(AuthManager::class);

            return new GateManager(
                $c,
                static fn (): ?Authenticatable => $auth->guard()->user(),
                (string) config('auth.authorization.policies_namespace', 'App\\Policies\\'),
            );
        });

        $this->commands([
            Authorization\Console\RoleCreateCommand::class,
            Authorization\Console\PermissionCreateCommand::class,
            Authorization\Console\RoleAssignCommand::class,
        ]);
    }

    public function boot(): void
    {
        $registry = $this->app->make(MiddlewareRegistry::class);

        $registry->register([
            'auth' => Authenticate::class,
            'can' => Authorize::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'admin' => Admin::class,
        ]);

        # После StartSession: ResolveUser учит $request->user() работать в обеих группах.
        $registry->appendToGroup('web', ResolveUser::class);
        $registry->appendToGroup('api', ResolveUser::class);

        $this->registerGateHooks();
        $this->registerUnauthenticatedRenderer();
    }

    /** Super admin и проверка прав через Gate: `$user->can('post.update')`, `can:post.update`. */
    private function registerGateHooks(): void
    {
        $gate = $this->app->make(GateManager::class);

        $gate->before(static function (Authenticatable $user): ?bool {
            $role = PermissionRegistrar::superAdminRole();

            return $role !== null && method_exists($user, 'hasRole') && $user->hasRole($role) ? true : null;
        });

        if (!(bool) config('auth.authorization.gate_checks_permissions', true)) {
            return;
        }

        # Не нашли право: null, решает политика или define(). Запретить через право нельзя, только разрешить.
        $gate->before(static function (Authenticatable $user, string $ability): ?bool {
            return method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($ability) ? true : null;
        });
    }

    /** Браузер без входа уходит на страницу входа, JSON-клиент получает 401. */
    private function registerUnauthenticatedRenderer(): void
    {
        $handler = $this->app->make(ExceptionHandlerContract::class);

        if (!$handler instanceof Handler) {
            return;
        }

        $handler->renderable(static function (AuthenticationException $e, Request $request) {
            $to = config('auth.redirect_to');

            if ($request->expectsJson() || !is_string($to) || $to === '') {
                return null;
            }

            return redirect($to);
        });
    }
}
