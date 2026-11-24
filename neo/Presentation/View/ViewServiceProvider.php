<?php

namespace Neo\Presentation\View;

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Contracts\ViewRendererContract;
use Neo\Network\Http\Request;
use Neo\Presentation\View\Infrastructure\HttpViewRenderer;

class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/View.php'), 'view');

        $this->app->singleton(ViewManager::class, function (ServiceContainer $c) {
            $manager = new ViewManager(config('view'));

            # $errors в каждом шаблоне (для @error): ошибки валидации из flash-сессии текущего запроса.
            # Функция читает запрос на каждый рендер, поэтому данные разных запросов не смешиваются.
            $context = $c->make(RequestContextContract::class);

            return $manager->shareUsing(static function () use ($context): array {
                $request = $context->isEntered() ? $context->request() : null;

                if (!$request instanceof Request || !$request->hasSession()) {
                    return ['errors' => []];
                }

                return ['errors' => (array) $request->session()->get('errors', [])];
            });
        });

        # ViewResponse (`response()->view()`, `Route::view()`) рисует шаблоны через ViewManager.
        $this->app->singleton(ViewRendererContract::class, static fn (ServiceContainer $c): ViewRendererContract => new HttpViewRenderer(
            $c->make(ViewManager::class),
        ));
    }
}
