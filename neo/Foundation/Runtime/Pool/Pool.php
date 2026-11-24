<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Pool;

use Closure;
use Neo\Foundation\Runtime\ChannelContract;
use Neo\Foundation\Runtime\Exceptions\PoolException;
use Neo\Foundation\Runtime\Exceptions\PoolExhaustedException;
use Neo\Foundation\Runtime\RuntimeContract;
use Throwable;

/**
 * Универсальный пул на каналах рантайма. В Swoole ожидание свободного ресурса приостанавливает
 * корутину, в sync-рантайме ждать некому, и пул сводится к «один ресурс на раз».
 *
 *  - factory   создаёт ресурс: `fn (): object`
 *  - validate  проверка перед выдачей после простоя: `fn (object): bool` (ping)
 *  - reset     очистка перед возвратом в пул (откат транзакции и т.п.): `fn (object): void`
 *  - destroy   закрытие ресурса: `fn (object): void`
 */
final class Pool implements PoolContract
{
    private readonly ChannelContract $slots;

    /** @var array<int, PoolEntry> выданные ресурсы по spl_object_id */
    private array $leased = [];

    private bool $closed = false;

    /** Сколько ресурсов сейчас существует (свободные и выданные). */
    private int $alive = 0;

    private int $reaper = 0;

    private readonly RuntimeContract $runtime;

    public function __construct(
        private readonly Closure $factory,
        private readonly PoolOptions $options,
        RuntimeContract $runtime,
        private readonly ?Closure $validate = null,
        private readonly ?Closure $reset = null,
        private readonly ?Closure $destroy = null,
    ) {
        $this->runtime = $runtime;
        $this->slots = $runtime->channel($options->max);

        for ($i = 0; $i < $options->max; $i++) {
            $this->slots->push(new PoolEntry());
        }
    }

    public function acquire(): object
    {
        if ($this->closed) {
            throw new PoolException('Пул закрыт.');
        }

        $entry = $this->slots->pop($this->options->acquireTimeout);

        if (!$entry instanceof PoolEntry) {
            throw new PoolExhaustedException(sprintf(
                'Пул исчерпан: все %d ресурсов заняты, ожидание %.1f с закончилось.',
                $this->options->max,
                $this->options->acquireTimeout,
            ));
        }

        try {
            $resource = $this->ensure($entry);
        } catch (Throwable $e) {
            # Слот не потерялся: пустой слот возвращается, следующий acquire попробует создать ресурс снова.
            $this->slots->push($entry);

            throw $e;
        }

        $this->leased[spl_object_id($resource)] = $entry;

        return $resource;
    }

    public function release(object $resource, bool $broken = false): void
    {
        $id = spl_object_id($resource);
        $entry = $this->leased[$id] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->leased[$id]);

        if (!$broken && !$this->closed && $this->reset !== null) {
            try {
                ($this->reset)($resource);
            } catch (Throwable) {
                $broken = true;
            }
        }

        if ($broken || $this->closed) {
            $this->destroyResource($entry);
        } else {
            $entry->lastUsedAt = microtime(true);
        }

        $this->slots->push($entry);
    }

    public function prune(): int
    {
        $closed = 0;
        $now = microtime(true);

        # Каждый слот вынимаем и сразу кладём обратно: канал не бывает «наполовину пустым» надолго.
        for ($i = $this->slots->length(); $i > 0; $i--) {
            $entry = $this->slots->pop(0.001);

            if (!$entry instanceof PoolEntry) {
                break;
            }

            if ($entry->resource !== null
                && $this->alive > $this->options->min
                && ($now - $entry->lastUsedAt) > $this->options->idleTimeout
            ) {
                $this->destroyResource($entry);
                $closed++;
            }

            $this->slots->push($entry);
        }

        return $closed;
    }

    /** Прогревает пул до min ресурсов (вызывать при старте воркера). */
    public function warmUp(): void
    {
        for ($i = $this->slots->length(); $i > 0 && $this->alive < $this->options->min; $i--) {
            $entry = $this->slots->pop(0.001);

            if (!$entry instanceof PoolEntry) {
                break;
            }

            try {
                if ($entry->resource === null) {
                    $this->fill($entry);
                }
            } catch (Throwable) {
                # Прогрев не критичен: ресурс создастся при первом acquire.
                $this->slots->push($entry);

                break;
            }

            $this->slots->push($entry);
        }
    }

    /**
     * Фоновая чистка: каждые reapInterval секунд закрывает ресурсы, простоявшие дольше idleTimeout
     * (но не меньше min). Нужна цикл событий; в sync-рантайме не запускается. Останавливается в close().
     */
    public function startReaper(): void
    {
        if ($this->reaper !== 0 || $this->closed || $this->options->reapInterval <= 0) {
            return;
        }

        $this->reaper = $this->runtime->tick($this->options->reapInterval, function (): void {
            if (!$this->closed) {
                $this->prune();
            }
        });
    }

    public function close(): void
    {
        $this->closed = true;

        if ($this->reaper !== 0) {
            $this->runtime->clearTick($this->reaper);
            $this->reaper = 0;
        }

        for ($i = $this->slots->length(); $i > 0; $i--) {
            $entry = $this->slots->pop(0.001);

            if ($entry instanceof PoolEntry) {
                $this->destroyResource($entry);
            }
        }

        $this->slots->close();
    }

    public function stats(): array
    {
        return [
            'total' => $this->alive,
            'idle' => $this->alive - count($this->leased),
            'leased' => count($this->leased),
            'max' => $this->options->max,
        ];
    }

    /** Готовит слот к выдаче: проверяет живой ресурс или создаёт новый. */
    private function ensure(PoolEntry $entry): object
    {
        if ($entry->resource !== null && !$this->usable($entry)) {
            $this->destroyResource($entry);
        }

        if ($entry->resource === null) {
            $this->fill($entry);
        }

        return $entry->resource;
    }

    private function usable(PoolEntry $entry): bool
    {
        $now = microtime(true);

        if (($now - $entry->createdAt) > $this->options->maxLifetime
            || ($now - $entry->lastUsedAt) > $this->options->idleTimeout
        ) {
            return false;
        }

        if ($this->validate !== null && ($now - $entry->lastUsedAt) >= $this->options->validateAfter) {
            try {
                return (bool) ($this->validate)($entry->resource);
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    private function fill(PoolEntry $entry): void
    {
        $entry->fill(($this->factory)());
        $this->alive++;
    }

    private function destroyResource(PoolEntry $entry): void
    {
        $resource = $entry->resource;
        $entry->clear();

        if ($resource === null) {
            return;
        }

        $this->alive--;

        if ($this->destroy !== null) {
            try {
                ($this->destroy)($resource);
            } catch (Throwable) {
                # Закрытие уже негодного ресурса не должно ломать запрос.
            }
        }
    }
}
