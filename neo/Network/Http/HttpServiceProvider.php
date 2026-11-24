<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Neo\Foundation\Kernel\Kernel;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Network\Cookie\Middleware\AddQueuedCookiesToResponse;
use Neo\Network\Cookie\Middleware\EncryptCookies;
use Neo\Network\Http\Console\RouteListCommand;
use Neo\Network\Http\Console\ServeCommand;
use Neo\Network\Http\Context\RequestContext;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Contracts\RequestContract;
use Neo\Network\Http\Exceptions\Handler;
use Neo\Network\Http\Foundation\Middleware\ConvertEmptyStringsToNull;
use Neo\Network\Http\Foundation\Middleware\HandleCors;
use Neo\Network\Http\Foundation\Middleware\TrimStrings;
use Neo\Network\Http\Foundation\Middleware\ValidatePostSize;
use Neo\Network\Http\Foundation\Middleware\VerifyCsrfToken;
use Neo\Network\Http\Routing\Middleware\SubstituteBindings;
use Neo\Network\Http\Routing\Middleware\ThrottleRequests;
use Neo\Network\Http\Routing\Middleware\ValidateSignature;
use Neo\Network\Http\Routing\MiddlewareRegistry;
use Neo\Network\Http\Routing\Router;
use Neo\Network\Http\Routing\UrlGenerator;
use Neo\Network\Http\Server\SapiEmitter;
use Neo\Network\Session\Middleware\StartSession;
use Neo\Security\Middleware\SecurityHeaders;
use Neo\Security\Middleware\TrustHosts;
use Neo\Security\Middleware\TrustProxies;
use Neo\Security\RateLimiter\Limit;
use Neo\Security\RateLimiter\RateLimiter;

/**
 * Подключает HTTP: контекст запроса, роутер, middleware, ядро и запуск через Kernel::run().
 *
 * Всё, что регистрируется здесь как singleton, не хранит данных запроса (они живут в RequestContext
 * и в scoped-сервисах), поэтому работает и в классическом SAPI, и в Swoole.
 */
final class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Http.php'), 'http');
        $this->loadConfig(base_path('app/Config/Cors.php'), 'cors');

        $container = $this->app->getContainer();

        # Контекст запроса. Один объект и под своим именем, и под контрактом;
        # он же хранилище scoped-сервисов контейнера.
        $context = new RequestContext();
        $this->app->instance(RequestContext::class, $context);
        $this->app->instance(RequestContextContract::class, $context);
        $container->useScope($context);

        # Request можно запросить типом откуда угодно (контроллер, сервис на запрос):
        # получите запрос ТЕКУЩЕГО контекста, а не первого попавшегося.
        $current = static fn (ServiceContainer $c): RequestContract => $c->make(RequestContextContract::class)->request();
        $container->scoped(RequestContract::class, $current);
        $container->scoped(Request::class, $current);

        $this->app->singleton(MiddlewareRegistry::class, static fn (): MiddlewareRegistry => new MiddlewareRegistry());

        $this->app->singleton(Router::class, static fn (ServiceContainer $c): Router => new Router(
            $c,
            $c->make(MiddlewareRegistry::class),
            $c->make(RequestContextContract::class),
        ));

        $this->app->singleton(ExceptionHandlerContract::class, static fn (ServiceContainer $c): ExceptionHandlerContract => new Handler($c));

        $this->app->singleton(UrlGenerator::class, static fn (ServiceContainer $c): UrlGenerator => new UrlGenerator(
            $c->make(Router::class),
            $c->make(RequestContextContract::class),
            $c,
        ));

        $this->app->singleton(Redirector::class, static fn (ServiceContainer $c): Redirector => new Redirector(
            $c->make(UrlGenerator::class),
            $c->make(RequestContextContract::class),
        ));

        $this->app->singleton(ResponseFactory::class, static fn (ServiceContainer $c): ResponseFactory => new ResponseFactory(
            $c->make(Redirector::class),
        ));

        $this->app->singleton(HttpKernel::class, static fn (ServiceContainer $c): HttpKernel => new HttpKernel(
            $c,
            $c->make(Router::class),
            $c->make(MiddlewareRegistry::class),
            $c->make(RequestContextContract::class),
            $c->make(ExceptionHandlerContract::class),
        ));

        # php index.php / FPM: Kernel::run() после boot() обрабатывает один запрос.
        # В консоли run() не вызывается, а чистый CLI SAPI пропускаем на всякий случай.
        $this->app->runUsing(static function (Kernel $app): void {
            if (PHP_SAPI === 'cli') {
                return;
            }

            $emitter = new SapiEmitter();

            $app->make(HttpKernel::class)->serve(Request::capture(), $emitter->emit(...));
        });

        $this->commands([ServeCommand::class, RouteListCommand::class]);
    }

    public function boot(): void
    {
        $registry = $this->app->make(MiddlewareRegistry::class);

        $registry->register([
            'cookies' => EncryptCookies::class,
            'cookies.queue' => AddQueuedCookiesToResponse::class,
            'session' => StartSession::class,
            'csrf' => VerifyCsrfToken::class,
            'cors' => HandleCors::class,
            'throttle' => ThrottleRequests::class,
            'signed' => ValidateSignature::class,
            'bindings' => SubstituteBindings::class,
            'security.headers' => SecurityHeaders::class,
            'proxies' => TrustProxies::class,
            'hosts' => TrustHosts::class,
            'trim' => TrimStrings::class,
            'nulls' => ConvertEmptyStringsToNull::class,
            'postsize' => ValidatePostSize::class,
        ]);

        # Свои псевдонимы из конфига.
        $registry->register((array) config('http.middleware.aliases', []));

        $registry->group('web', (array) config('http.middleware.groups.web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ]));

        $registry->group('api', (array) config('http.middleware.groups.api', [
            'throttle:api',
            SubstituteBindings::class,
        ]));

        foreach ((array) config('http.middleware.groups', []) as $name => $middleware) {
            if (!in_array($name, ['web', 'api'], true)) {
                $registry->group((string) $name, (array) $middleware);
            }
        }

        # Глобальные: выполняются на каждый запрос ДО маршрутизации.
        $registry->global((array) config('http.middleware.global', [
            TrustProxies::class,
            TrustHosts::class,
            HandleCors::class,
            SecurityHeaders::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]));

        # Относительный порядок маршрутных middleware, как бы их ни перечислили.
        $registry->priority((array) config('http.middleware.priority', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            VerifyCsrfToken::class,
            'Neo\\Security\\Authentication\\Middleware\\Authenticate',
            ThrottleRequests::class,
            'Neo\\Security\\Authorization\\Middleware\\Authorize',
            SubstituteBindings::class,
        ]));

        # Лимитер «api» по умолчанию; приложение может переопределить его в своём провайдере.
        $limiter = $this->app->make(RateLimiter::class);

        if ($limiter->limiter('api') === null) {
            $limiter->for('api', static fn (Request $request): Limit => Limit::perMinute((int) config('http.api_rate_limit', 60))->by($request->ip()));
        }

        $this->loadRouteFiles();
    }

    private function loadRouteFiles(): void
    {
        $router = $this->app->make(Router::class);

        foreach ((array) config('http.routes', []) as $definition) {
            $path = is_array($definition) ? ($definition['path'] ?? null) : $definition;

            if (!is_string($path) || !is_file($path)) {
                continue;
            }

            $attributes = is_array($definition)
                ? array_filter([
                    'prefix' => $definition['prefix'] ?? null,
                    'middleware' => $definition['middleware'] ?? null,
                    'as' => $definition['name'] ?? null,
                ], static fn ($v): bool => $v !== null)
                : [];

            $router->group($attributes, $path);
        }

        # Маршруты пакетов (loadRoutes() в их провайдерах): без обёрток.
        foreach ($this->app->resources('routes') as $path) {
            if (is_string($path) && is_file($path)) {
                $router->group([], $path);
            }
        }
    }
}
