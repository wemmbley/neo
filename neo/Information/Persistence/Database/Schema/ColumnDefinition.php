<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema;

/**
 * Описание колонки. Цепочка: `$table->string('name')->nullable()->unique()`.
 */
class ColumnDefinition
{
    public bool $nullable = false;
    public mixed $default = null;
    public bool $hasDefault = false;
    public bool $unsigned = false;
    public bool $autoIncrement = false;
    public bool $primary = false;
    public bool $unique = false;
    public bool $index = false;
    public bool $useCurrent = false;
    public ?string $comment = null;
    public ?string $references = null;
    public ?string $on = null;
    public ?string $onDelete = null;
    public ?string $onUpdate = null;
    public ?string $fkName = null;

    /** Подсказка декларативному потоку: колонка раньше называлась так. */
    public ?string $renamedFrom = null;

    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly array $parameters = [],
    ) {}

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function useCurrent(): static
    {
        $this->useCurrent = true;

        return $this;
    }

    public function unsigned(): static
    {
        $this->unsigned = true;

        return $this;
    }

    public function primary(): static
    {
        $this->primary = true;

        return $this;
    }

    public function unique(): static
    {
        $this->unique = true;

        return $this;
    }

    public function index(): static
    {
        $this->index = true;

        return $this;
    }

    public function renamedFrom(string $oldName): static
    {
        $this->renamedFrom = $oldName;

        return $this;
    }

    public function comment(string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function constrained(string $table, string $column = 'id'): static
    {
        $this->references = $column;
        $this->on = $table;

        return $this;
    }

    public function cascadeOnDelete(): static
    {
        $this->onDelete = 'cascade';

        return $this;
    }

    public function nullOnDelete(): static
    {
        $this->onDelete = 'set null';

        return $this;
    }

    public function cascadeOnUpdate(): static
    {
        $this->onUpdate = 'cascade';

        return $this;
    }
}
