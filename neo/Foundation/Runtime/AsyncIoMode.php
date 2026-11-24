<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Как рантайм исполняет асинхронные задачи (см. Runtime Specification, п. 6).
 */
enum AsyncIoMode: string
{
    /** Задачи выполняются последовательно и синхронно. Корректный фасад без выигрыша в скорости. */
    case None = 'none';

    /** Конкурентность поверх Fiber и event loop (Revolt/Amp/ReactPHP). */
    case Fibers = 'fibers';

    /** Настоящие корутины (Swoole / OpenSwoole): ввод-вывод не блокирует воркер. */
    case Coroutines = 'coroutines';
}
