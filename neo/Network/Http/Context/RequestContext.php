<?php

declare(strict_types=1);

namespace Neo\Network\Http\Context;

use Fiber;
use LogicException;
use Neo\Foundation\Kernel\ScopeContract;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Contracts\RequestContract;
use Neo\Network\Http\Contracts\ResponseContract;
use WeakMap;

/**
 * Контекст текущего HTTP-запроса. Один класс для всех сред, без настроек:
 *
 *  - внутри корутины Swoole состояние хранится в контексте корутины
 *    (дочерние корутины видят состояние родителя);
 *  - внутри Fiber (тесты, параллельные задачи) в WeakMap по Fiber;
 *  - иначе в обычной переменной: классический SAPI, один запрос на процесс.
 *
 * Swoole определяется на лету через class_exists(..., false): без расширения этот
 * код просто не выполняется, расширение не требуется.
 *
 * Singleton-сервисы (Cookie, Session, Router) НЕ хранят данные запроса в свойствах:
 * они кладут их сюда, поэтому два запроса, обрабатываемые одновременно, не смешиваются.
 */
final class RequestContext implements RequestContextContract, ScopeContract
{
    private const string KEY = 'neo.http.context';

    private ?ContextState $main = null;

    /** @var WeakMap<Fiber, ContextState> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function enter(RequestContract $request, ResponseContract $response): void
    {
        $state = new ContextState($request, $response);

        if ($this->inSwoole()) {
            $coroutine = \Swoole\Coroutine::getContext();
            $coroutine[self::KEY] = $state;

            return;
        }

        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->main = $state;

            return;
        }

        $this->fibers[$fiber] = $state;
    }

    public function leave(): void
    {
        if ($this->inSwoole()) {
            $coroutine = \Swoole\Coroutine::getContext();
            unset($coroutine[self::KEY]);

            return;
        }

        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->main = null;

            return;
        }

        unset($this->fibers[$fiber]);
    }

    public function isEntered(): bool
    {
        return $this->find() !== null;
    }

    public function request(): RequestContract
    {
        return $this->state()->request;
    }

    public function response(): ResponseContract
    {
        return $this->state()->response;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state()->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->state()->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->state()->data[$key]);
    }

    // ------------------------------------------------------------------
    // ScopeContract: scoped-сервисы контейнера живут в состоянии запроса
    // ------------------------------------------------------------------

    public function scopeActive(): bool
    {
        return $this->find() !== null;
    }

    public function hasScoped(string $abstract): bool
    {
        $state = $this->find();

        return $state !== null && array_key_exists($abstract, $state->scoped);
    }

    public function getScoped(string $abstract): mixed
    {
        return $this->state()->scoped[$abstract];
    }

    public function putScoped(string $abstract, mixed $instance): void
    {
        $this->state()->scoped[$abstract] = $instance;
    }

    // ------------------------------------------------------------------

    private function state(): ContextState
    {
        return $this->find() ?? throw new LogicException('HTTP request context has not been entered.');
    }

    private function find(): ?ContextState
    {
        if ($this->inSwoole()) {
            $cid = \Swoole\Coroutine::getCid();

            # Идём от текущей корутины к родителям: задача, запущенная go() внутри запроса,
            # продолжает видеть запрос-родитель.
            while ($cid > 0) {
                $context = \Swoole\Coroutine::getContext($cid);

                if ($context !== null && isset($context[self::KEY])) {
                    return $context[self::KEY];
                }

                $cid = (int) \Swoole\Coroutine::getPcid($cid);
            }

            return null;
        }

        $fiber = Fiber::getCurrent();

        if ($fiber !== null && isset($this->fibers[$fiber])) {
            return $this->fibers[$fiber];
        }

        return $this->main;
    }

    private function inSwoole(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }
}
