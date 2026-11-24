<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Неизменяемая реализация {@see RuntimeCapabilities}. Готовые профили: sync(), swoole().
 * Для нового рантайма (RoadRunner, FrankenPHP) достаточно создать свой набор флагов.
 */
final class Capabilities implements RuntimeCapabilities
{
    public function __construct(
        private readonly bool $concurrentRequestsPerWorker = false,
        private readonly bool $coroutines = false,
        private readonly bool $threads = false,
        private readonly AsyncIoMode $asyncIo = AsyncIoMode::None,
        private readonly bool $requiresConnectionPool = false,
        private readonly bool $supportsBackgroundTasks = false,
        private readonly bool $supportsStreaming = false,
        private readonly bool $supportsHotReload = false,
    ) {}

    /** FPM, Apache, `php -S`, консоль: один запрос на процесс, без конкурентности. */
    public static function sync(): self
    {
        return new self();
    }

    /** Swoole / OpenSwoole (колонка матрицы из Runtime Specification). */
    public static function swoole(): self
    {
        return new self(
            concurrentRequestsPerWorker: true,
            coroutines: true,
            asyncIo: AsyncIoMode::Coroutines,
            requiresConnectionPool: true,
            supportsBackgroundTasks: true,
            supportsStreaming: true,
            supportsHotReload: true,
        );
    }

    /** Заготовка под RoadRunner: процессы, без корутин, без пула. */
    public static function roadRunner(): self
    {
        return new self(supportsBackgroundTasks: true, supportsStreaming: true, supportsHotReload: true);
    }

    /** Заготовка под FrankenPHP (worker mode): потоки ZTS. */
    public static function frankenPhp(): self
    {
        return new self(threads: true, supportsBackgroundTasks: true, supportsStreaming: true, supportsHotReload: true);
    }

    public function concurrentRequestsPerWorker(): bool { return $this->concurrentRequestsPerWorker; }

    public function coroutines(): bool { return $this->coroutines; }

    public function threads(): bool { return $this->threads; }

    public function asyncIo(): AsyncIoMode { return $this->asyncIo; }

    public function requiresConnectionPool(): bool { return $this->requiresConnectionPool; }

    public function supportsBackgroundTasks(): bool { return $this->supportsBackgroundTasks; }

    public function supportsStreaming(): bool { return $this->supportsStreaming; }

    public function supportsHotReload(): bool { return $this->supportsHotReload; }
}
