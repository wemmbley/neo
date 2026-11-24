<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Канал для общения корутин и ожидания ресурса. В sync-рантайме это обычная очередь без ожидания:
 * pop() пустого канала сразу возвращает false.
 *
 * Значения false в канал класть нельзя: false означает «таймаут или канал закрыт».
 */
interface ChannelContract
{
    /** @param float $timeout секунды, отрицательное значение = ждать бесконечно */
    public function push(mixed $value, float $timeout = -1): bool;

    /** @return mixed значение, либо false при таймауте или закрытом канале */
    public function pop(float $timeout = -1): mixed;

    public function length(): int;

    public function isEmpty(): bool;

    public function close(): void;
}
