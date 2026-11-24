<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

use Neo\Foundation\Runtime\Swoole\SwooleApi;
use Neo\Foundation\Runtime\Swoole\SwooleRuntime;
use Neo\Foundation\Runtime\Sync\SyncRuntime;

/**
 * Текущий рантайм процесса. Синхронный PHP ничего не настраивает: по умолчанию это SyncRuntime.
 *
 * Адаптер долгоживущего сервера делает `Runtime::use(new SwooleRuntime())` при старте воркера.
 * Если никто ничего не выбрал, но код выполняется внутри корутины Swoole (свой скрипт с
 * `Co\run()`), рантайм определяется на лету. Поэтому синхронный движок можно в любой момент
 * запустить под корутинами и вернуть обратно без правок кода.
 */
final class Runtime
{
    private static ?RuntimeContract $current = null;

    private static ?SyncRuntime $sync = null;

    private static ?SwooleRuntime $swoole = null;

    public static function current(): RuntimeContract
    {
        if (self::$current !== null) {
            return self::$current;
        }

        if (SwooleApi::inCoroutine()) {
            return self::$swoole ??= new SwooleRuntime();
        }

        return self::$sync ??= new SyncRuntime();
    }

    /** Явно выбирает рантайм; null возвращает автоопределение. */
    public static function use(?RuntimeContract $runtime): void
    {
        self::$current = $runtime;
    }

    public static function capabilities(): RuntimeCapabilities
    {
        return self::current()->capabilities();
    }
}
