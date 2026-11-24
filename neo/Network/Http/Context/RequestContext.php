<?php

declare(strict_types=1);

namespace Neo\Network\Http\Context;

use LogicException;
use Neo\Foundation\Kernel\ScopeContract;
use Neo\Foundation\Runtime\Context;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Contracts\RequestContract;
use Neo\Network\Http\Contracts\ResponseContract;

/**
 * Контекст текущего HTTP-запроса. Один класс для всех сред, без настроек.
 *
 * Состояние запроса лежит в контексте исполнения текущего рантайма ({@see Context}):
 *  - Swoole / OpenSwoole: в контексте корутины запроса, дочерние корутины видят состояние родителя;
 *  - Fiber (тесты, параллельные задачи): отдельно на каждый Fiber;
 *  - классический SAPI: в хранилище процесса, один запрос на процесс.
 *
 * Singleton-сервисы (Cookie, Session, Router) НЕ хранят данные запроса в свойствах:
 * они кладут их сюда, поэтому два запроса, обрабатываемые одновременно, не смешиваются.
 */
final class RequestContext implements RequestContextContract, ScopeContract
{
    private const string KEY = 'neo.http.context';

    /** Только счётчик экземпляров для уникального ключа, данных запроса здесь нет. */
    private static int $instances = 0;

    /** Свой ключ на каждый экземпляр: два независимых RequestContext (тесты) не видят друг друга. */
    private readonly string $key;

    public function __construct()
    {
        $this->key = self::KEY . '#' . ++self::$instances;
    }

    public function enter(RequestContract $request, ResponseContract $response): void
    {
        Context::set($this->key, new ContextState($request, $response));
    }

    public function leave(): void
    {
        Context::destroy($this->key);
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
        # Идём от текущей корутины к родителям: задача, запущенная go() внутри запроса,
        # продолжает видеть запрос-родитель.
        $state = Context::inherited($this->key);

        return $state instanceof ContextState ? $state : null;
    }
}
