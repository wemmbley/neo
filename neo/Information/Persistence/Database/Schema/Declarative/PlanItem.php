<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

/** Один будущий коммит: изменение вклада одного файла. */
final class PlanItem
{
    /**
     * @param 'apply'|'rollback'|'purge' $action
     * @param array{tables: array} $snapshot состояние вклада после коммита
     * @param list<Operation> $operations
     */
    public function __construct(
        public readonly string $owner,
        public readonly string $name,
        public readonly string $action,
        public readonly string $hash,
        public readonly array $snapshot,
        public readonly array $operations,
        public readonly string $label,
    ) {}

    public function key(): string
    {
        return $this->owner . '::' . $this->name;
    }
}
