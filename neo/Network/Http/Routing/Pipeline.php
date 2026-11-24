<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Pipeline\Pipeline as BasePipeline;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\Exceptions\HttpResponseException;
use Throwable;

/**
 * HTTP-конвейер: исключение внутри слоя превращается в Response на месте, поэтому внешние
 * слои (сессия, куки, CORS) всё равно получают ответ и успевают отработать: например,
 * сохранить flash-ошибки валидации в сессию.
 */
final class Pipeline extends BasePipeline
{
    public function __construct(ServiceContainer $container, private readonly ExceptionHandlerContract $handler)
    {
        parent::__construct($container);
    }

    protected function handleException(mixed $passable, Throwable $e): mixed
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        $this->handler->report($e);

        return $this->handler->render($passable, $e);
    }
}
