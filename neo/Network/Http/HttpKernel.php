<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Closure;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Routing\MiddlewareRegistry;
use Neo\Network\Http\Routing\Pipeline;
use Neo\Network\Http\Routing\Router;
use Throwable;

/**
 * Ядро HTTP: жизненный цикл запроса (см. docs/Specifications/Http Specification.md).
 *
 *   Request -> глобальные middleware -> маршрутизация -> маршрутные middleware -> действие
 *           -> Response -> (обратно через middleware) -> отправка -> terminate
 *
 * Класс не хранит состояния запроса, один экземпляр обслуживает любое число одновременных
 * запросов. Всё, что относится к запросу, лежит в RequestContext.
 */
class HttpKernel
{
    /** @var list<Closure(Request, Response): void> */
    protected array $terminating = [];

    public function __construct(
        protected readonly ServiceContainer $container,
        protected readonly Router $router,
        protected readonly MiddlewareRegistry $middleware,
        protected readonly RequestContextContract $context,
        protected readonly ExceptionHandlerContract $handler,
    ) {}

    /**
     * Полный цикл для одного запроса: вход в контекст, обработка, отправка, terminate, выход.
     * Именно его вызывают адаптеры среды (SAPI, Swoole) и тесты.
     *
     * @param Closure(Response): void $emit отправляет готовый ответ клиенту
     */
    public function serve(Request $request, Closure $emit): void
    {
        $this->context->enter($request, new Response());

        try {
            $response = $this->handle($request);
            $emit($response);
            $this->terminate($request, $response);
        } finally {
            $this->context->leave();
        }
    }

    /**
     * Обрабатывает запрос и возвращает готовый к отправке ответ. Исключения не выходят наружу.
     * Контекст запроса должен быть открыт ({@see serve()}).
     */
    public function handle(Request $request): Response
    {
        try {
            $response = $this->sendRequestThroughRouter($request);
        } catch (Throwable $e) {
            $this->handler->report($e);
            $response = $this->handler->render($request, $e);
        }

        $this->flushQueuedCookies($response);

        return $response->prepare($request);
    }

    protected function sendRequestThroughRouter(Request $request): Response
    {
        return (new Pipeline($this->container, $this->handler))
            ->send($request)
            ->through($this->middleware->getGlobal())
            ->then(fn (Request $request): Response => $this->router->dispatch($request));
    }

    /**
     * Куки, поставленные через Cookie::set()/queue() и Session, лежат в «ожидающем» ответе контекста.
     * Если их не перенёс middleware AddQueuedCookiesToResponse (маршруты вне группы web),
     * переносим здесь, чтобы кука не потерялась молча.
     */
    protected function flushQueuedCookies(Response $response): void
    {
        if (!$this->context->isEntered()) {
            return;
        }

        $pending = $this->context->response();

        if (!$pending instanceof Response || $pending === $response) {
            return;
        }

        foreach ($pending->cookies() as $cookie) {
            $response->withCookie($cookie);
        }

        $pending->setCookies([]);
    }

    /** Вызывается ПОСЛЕ отправки ответа: middleware с методом terminate() и отложенные задачи. */
    /** @var array<string, bool> */
    private static array $terminable = [];

    private static function hasTerminate(string $class): bool
    {
        return self::$terminable[$class] ??= method_exists($class, 'terminate');
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $items = [...$this->middleware->getGlobal()];

            $route = $request->route();

            if ($route !== null) {
                array_push($items, ...$route->computedMiddleware(fn ($r): array => $this->router->gatherRouteMiddleware($r)));
            }
        } catch (Throwable) {
            # Ошибку конфигурации middleware уже отдали в ответе запроса; terminate просто пропускаем.
            return;
        }

        foreach ($items as $item) {
            try {
                # Экземпляр создаём, только если у класса вообще есть terminate(): иначе на каждый запрос
                # собирался бы второй комплект всех middleware ради пустого вызова.
                $instance = match (true) {
                    is_object($item) && !$item instanceof Closure => $item,
                    is_string($item) => self::hasTerminate($class = explode(':', $item, 2)[0]) ? $this->container->make($class) : null,
                    default => null,
                };

                if ($instance !== null && method_exists($instance, 'terminate')) {
                    $instance->terminate($request, $response);
                }
            } catch (Throwable $e) {
                $this->handler->report($e);
            }
        }

        foreach ($this->terminating as $callback) {
            try {
                $callback($request, $response);
            } catch (Throwable $e) {
                $this->handler->report($e);
            }
        }
    }

    /** @param Closure(Request, Response): void $callback */
    public function terminating(Closure $callback): static
    {
        $this->terminating[] = $callback;

        return $this;
    }
}
