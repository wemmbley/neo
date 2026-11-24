<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

use Closure;

/**
 * Статический доступ к контексту текущего исполнения (в духе `Hyperf\Context\Context`).
 *
 *   Context::set('user', $user);                 // живёт до конца запроса/корутины
 *   Context::get('user');
 *   Context::getOrSet('trace', fn () => uuid()); // лениво, один раз на контекст
 *   Context::inherited('request');               // ищет у родителей: go() внутри запроса видит запрос
 *
 * Это не глобальное состояние: данные лежат в {@see ExecutionContext} текущего рантайма и
 * исчезают вместе с корутиной. В sync-режиме это обычное хранилище процесса, поэтому один и тот
 * же код работает и без Swoole.
 */
final class Context
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Runtime::current()->context()->get($key, $default);
    }

    public static function set(string $key, mixed $value): mixed
    {
        Runtime::current()->context()->set($key, $value);

        return $value;
    }

    public static function has(string $key): bool
    {
        return Runtime::current()->context()->has($key);
    }

    public static function destroy(string $key): void
    {
        Runtime::current()->context()->forget($key);
    }

    /**
     * Значение контекста; если его нет, вычисляет $value (Closure вызывается) и сохраняет.
     */
    public static function getOrSet(string $key, mixed $value): mixed
    {
        $context = Runtime::current()->context();

        if ($context->has($key)) {
            return $context->get($key);
        }

        $value = $value instanceof Closure ? $value() : $value;
        $context->set($key, $value);

        return $value;
    }

    /** Меняет значение функцией: `Context::override('count', fn ($n) => ($n ?? 0) + 1)`. */
    public static function override(string $key, Closure $closure): mixed
    {
        $context = Runtime::current()->context();
        $value = $closure($context->get($key));
        $context->set($key, $value);

        return $value;
    }

    /** Ищет ключ в текущем контексте, затем у родителей (ссылка на parent, не копирование). */
    public static function inherited(string $key, mixed $default = null): mixed
    {
        $context = Runtime::current()->context();

        while ($context !== null) {
            if ($context->has($key)) {
                return $context->get($key);
            }

            $context = $context->parent();
        }

        return $default;
    }

    /** Явно копирует ключи родителя в текущий контекст (для корутины, которой нужна своя копия). */
    public static function copyFromParent(string ...$keys): void
    {
        $context = Runtime::current()->context();
        $parent = $context->parent();

        if ($parent === null) {
            return;
        }

        foreach ($keys as $key) {
            if ($parent->has($key)) {
                $context->set($key, $parent->get($key));
            }
        }
    }
}
