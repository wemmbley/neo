<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Support;

use Closure;

/** Один измеряемый сценарий: имя, группа, описание и тело. */
final class Scenario
{
    /**
     * @param Closure(Context): mixed $body
     * @param bool $heavy тяжёлый: в пакетном прогоне получает меньше итераций
     */
    public function __construct(
        public readonly string $name,
        public readonly string $group,
        public readonly string $description,
        private readonly Closure $body,
        public readonly bool $heavy = false,
    ) {}

    public function run(Context $context): mixed
    {
        return ($this->body)($context);
    }
}
