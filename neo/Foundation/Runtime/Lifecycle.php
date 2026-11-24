<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

use Closure;
use Neo\Foundation\Kernel\ServiceContainer;
use Throwable;

/**
 * Жизненный цикл воркера и запроса (Runtime Specification, п. 4.2 и 4.4/4.5).
 *
 *   workerStart()  один раз при старте воркера: открыть ресурсы воркера, снять «унаследованные» от master
 *   requestEnd()   после каждого запроса: reset() всех зарегистрированных Resettable
 *   workerStop()   graceful-остановка воркера: закрыть пулы и соединения
 *
 * Адаптер рантайма (SwooleServer) вызывает эти методы; синхронный PHP их не вызывает вовсе.
 * Ошибка одного обработчика не мешает остальным и не роняет воркер: она уходит в error_log.
 */
final class Lifecycle
{
    /** @var list<Closure(): void> */
    private array $workerStart = [];

    /** @var list<Closure(): void> */
    private array $workerStop = [];

    /** @var list<Resettable|Closure(): void|class-string<Resettable>> */
    private array $resettables = [];

    public function __construct(private readonly ?ServiceContainer $container = null) {}

    /** @param Closure(): void $callback */
    public function onWorkerStart(Closure $callback): static
    {
        $this->workerStart[] = $callback;

        return $this;
    }

    /** @param Closure(): void $callback */
    public function onWorkerStop(Closure $callback): static
    {
        $this->workerStop[] = $callback;

        return $this;
    }

    /**
     * Регистрирует то, что нужно сбросить после запроса. Класс-строка берётся из контейнера
     * только если экземпляр уже создан (сброс не должен инстанцировать сервисы).
     *
     * @param Resettable|Closure(): void|class-string<Resettable> $item
     */
    public function resettable(Resettable|Closure|string $item): static
    {
        $this->resettables[] = $item;

        return $this;
    }

    public function workerStart(): void
    {
        $this->each($this->workerStart, 'worker start');
    }

    public function workerStop(): void
    {
        $this->each($this->workerStop, 'worker stop');
    }

    /** Состояние запроса сброшено: вызывать в `finally` после обработки запроса. */
    public function requestEnd(): void
    {
        foreach ($this->resettables as $item) {
            try {
                $target = match (true) {
                    $item instanceof Resettable, $item instanceof Closure => $item,
                    default => $this->container?->hasInstance($item) ? $this->container->getInstance($item) : null,
                };

                if ($target instanceof Closure) {
                    $target();
                } elseif ($target instanceof Resettable) {
                    $target->reset();
                }
            } catch (Throwable $e) {
                error_log('[runtime] reset failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }

    /** @param list<Closure(): void> $callbacks */
    private function each(array $callbacks, string $stage): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                error_log("[runtime] {$stage} failed: " . $e::class . ': ' . $e->getMessage());
            }
        }
    }
}
