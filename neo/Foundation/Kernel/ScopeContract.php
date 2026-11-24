<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

/**
 * Хранилище scoped-сервисов контейнера (см. ServiceContainer::scoped()).
 *
 * Реализует HTTP-контекст запроса: данные привязаны к запросу (корутине, Fiber),
 * а не к процессу, поэтому одновременные запросы не видят чужое состояние.
 */
interface ScopeContract
{
    /** Есть ли сейчас активный scope (внутри запроса). */
    public function scopeActive(): bool;

    public function hasScoped(string $abstract): bool;

    public function getScoped(string $abstract): mixed;

    public function putScoped(string $abstract, mixed $instance): void;
}
