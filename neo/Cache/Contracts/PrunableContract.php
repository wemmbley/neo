<?php

declare(strict_types=1);

namespace Neo\Cache\Contracts;

interface PrunableContract
{
    /**
     * Удалить просроченные записи. Возвращает число удалённых.
     */
    public function prune(): int;
}