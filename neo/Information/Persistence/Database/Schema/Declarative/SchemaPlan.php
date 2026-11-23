<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

/** План применения: что будет закоммичено и в каком порядке выполнять операции. */
final class SchemaPlan
{
    /**
     * @param list<PlanItem> $items
     * @param list<Operation> $sequence операции в порядке выполнения
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $items,
        public readonly array $sequence,
        public readonly array $warnings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<Operation> */
    public function destructive(): array
    {
        return array_values(array_filter($this->sequence, static fn (Operation $o) => $o->isDestructive()));
    }
}
