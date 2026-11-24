<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Возможности рантайма. Остальной код опирается на них, а не на имя рантайма:
 * `if ($runtime->capabilities()->requiresConnectionPool())`, а не `if ($runtime->name() === 'swoole')`.
 */
interface RuntimeCapabilities
{
    /** Несколько запросов одновременно в одном воркере. */
    public function concurrentRequestsPerWorker(): bool;

    /** Есть корутины/файберы на уровне PHP. */
    public function coroutines(): bool;

    /** Воркеры это потоки (ZTS), а не процессы. */
    public function threads(): bool;

    public function asyncIo(): AsyncIoMode;

    /** Соединение нельзя делить между запросами. */
    public function requiresConnectionPool(): bool;

    public function supportsBackgroundTasks(): bool;

    /** SSE / chunked / WebSocket. */
    public function supportsStreaming(): bool;

    public function supportsHotReload(): bool;
}
