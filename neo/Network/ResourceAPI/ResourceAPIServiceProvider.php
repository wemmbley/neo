<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Persistence\ORM\Builder;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\Exceptions\Handler;
use Neo\Network\Http\Request;
use Neo\Network\Http\Routing\Router;
use Neo\Network\ResourceAPI\Exceptions\ModelNotFoundResourceAPIException;
use Neo\Security\Authentication\AuthManager;
use Neo\Security\Authorization\Gate\GateManager;
use Throwable;

/**
 * Подключение ResourceAPI. Добавить в `app.providers` после HttpServiceProvider и ORMServiceProvider
 * (и после AuthServiceProvider, если нужна авторизация через Policy).
 *
 *   Route::prefix('api/v1')->middleware(['api'])->group(function () {
 *       Route::resource(PostResourceAPI::class);
 *   });
 *   Route::middleware(['web', 'auth'])->documentation('/docs/api');
 */
class ResourceAPIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(__DIR__ . '/config.php', 'resourceapi');
        $this->publish([__DIR__ . '/config.php' => base_path('app/Config/ResourceAPI.php')], 'resource-api');

        $this->app->singleton(ResourceRegistry::class, fn () => new ResourceRegistry());
        $this->app->singleton(ResourceAuthorizer::class, fn () => self::authorizer($this->app->getContainer()));
        $this->app->singleton(OrmResourceRepository::class, fn () => new OrmResourceRepository(
            $this->app->make(ResourceRegistry::class),
            $this->app->make(ResourceAuthorizer::class),
        ));
        $this->app->singleton(ResourceController::class, fn () => new ResourceController(
            $this->app->make(ResourceRegistry::class),
            $this->app->make(OrmResourceRepository::class),
            $this->app->make(ResourceAuthorizer::class),
        ));

        # Роутер читает файлы маршрутов в boot() HttpServiceProvider, раньше boot() этого провайдера:
        # макрос Route::documentation() и настройки должны быть готовы уже на этапе register().
        ResourceAPI::configure([...(array) config('resourceapi', []), 'debug' => (bool) config('app.debug', false)]);

        $handler = $this->app->make(ExceptionHandlerContract::class);

        self::wire($this->app->make(Router::class), $this->app->getContainer(), $handler instanceof Handler ? $handler : null);
    }

    /**
     * Подключает ResourceAPI к роутеру, ORM и Handler. Вынесено из boot(), чтобы тесты
     * могли собрать то же самое без ядра приложения.
     */
    public static function wire(Router $router, ServiceContainer $container, ?Handler $handler = null): void
    {
        if (!$container->has(ResourceRegistry::class)) {
            $container->singleton(ResourceRegistry::class, fn () => new ResourceRegistry());
        }

        if (!$container->has(ResourceAuthorizer::class)) {
            $container->singleton(ResourceAuthorizer::class, fn () => self::authorizer($container));
        }

        $registry = static fn (): ResourceRegistry => $container->make(ResourceRegistry::class);
        $authorizer = static fn (): ?ResourceAuthorizer => $container->make(ResourceAuthorizer::class);
        $repository = static fn (): OrmResourceRepository => $container->has(OrmResourceRepository::class)
            ? $container->make(OrmResourceRepository::class)
            : new OrmResourceRepository($registry(), $authorizer());

        # Post::filter($request->query()) : те же фильтры, сортировка и include, что у ресурса модели.
        Builder::macro('filter', function (array $params = []) use ($registry, $authorizer, $repository): Builder {
            $class = $this->getModel()::class;
            $resource = $registry()->forModel($class)
                ?? throw new ModelNotFoundResourceAPIException("Resource for model {$class} is not registered.");

            return $repository()->query($resource, ResourceQuery::parse($resource, $params, $registry(), $authorizer()), $this);
        });

        $router->macro('documentation', function (string $uri) use ($container, $registry): void {
            $registry()->registerDocumentation($uri);

            if (!ResourceAPI::setting('documentation.enabled')) {
                return;
            }

            $docs = $container->has(DocsController::class)
                ? $container->make(DocsController::class)
                : new DocsController($registry(), $container->make(ResourceAuthorizer::class));

            $uri = '/' . trim($uri, '/');
            $this->get($uri, fn (Request $request) => $docs->page($request))->name('resource-api.docs');
            $this->get($uri . '/schema.json', fn (Request $request) => $docs->data($request))->name('resource-api.docs.data');
        });

        $handler?->renderable(
            static fn (Throwable $e, Request $request) => ($container->has(ResourceController::class)
                ? $container->make(ResourceController::class)
                : new ResourceController($registry(), new OrmResourceRepository($registry(), $authorizer()), $authorizer()))->render($e, $request),
            true,
        );
    }

    /** Авторизация через Gate, если модуль Auth подключён; без него только `$actions`. */
    private static function authorizer(ServiceContainer $container): ?ResourceAuthorizer
    {
        return $container->has(GateManager::class) && $container->has(AuthManager::class)
            ? new GateResourceAuthorizer($container->make(GateManager::class), $container->make(AuthManager::class))
            : null;
    }
}