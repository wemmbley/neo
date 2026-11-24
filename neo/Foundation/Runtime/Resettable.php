<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Сервис с состоянием, которое нужно очистить между запросами в долгоживущем воркере.
 * Регистрируется через {@see Lifecycle::resettable()}.
 */
interface Resettable
{
    public function reset(): void;
}
