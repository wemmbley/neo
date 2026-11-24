<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Tasks;

/**
 * Фоновая задача. Создаётся контейнером в процессе, который её выполняет (task worker или
 * корутина), поэтому зависимости приходят через конструктор. Данные передаются только в $payload:
 * замыкания и объекты через границу процесса не проходят.
 */
interface Task
{
    /** @param array<string, mixed> $payload скалярные значения и массивы (сериализуются) */
    public function handle(array $payload): void;
}
