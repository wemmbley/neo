<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema;

/**
 * Описание таблицы для create / table (alter) / drop.
 */
class Blueprint
{
    /** @var list<ColumnDefinition> */
    public array $columns = [];

    /** @var list<array{type: string, columns: list<string>, name: ?string}> */
    public array $indexes = [];

    /** @var list<string> */
    public array $dropColumns = [];

    public bool $creating = false;

    public function __construct(public readonly string $table) {}

    private function add(string $type, string $name, array $params = []): ColumnDefinition
    {
        return $this->columns[] = new ColumnDefinition($type, $name, $params);
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($name);
    }

    public function increments(string $name): ColumnDefinition
    {
        $c = $this->add('integer', $name);
        $c->autoIncrement = true;
        $c->primary = true;
        $c->unsigned = true;

        return $c;
    }

    public function bigIncrements(string $name): ColumnDefinition
    {
        $c = $this->add('bigInteger', $name);
        $c->autoIncrement = true;
        $c->primary = true;
        $c->unsigned = true;

        return $c;
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->add('integer', $name);
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->add('bigInteger', $name);
    }

    public function foreignId(string $name): ColumnDefinition
    {
        return $this->add('bigInteger', $name)->unsigned();
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add('string', $name, ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->add('text', $name);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->add('boolean', $name);
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->add('float', $name);
    }

    public function decimal(string $name, int $total = 10, int $places = 2): ColumnDefinition
    {
        return $this->add('decimal', $name, ['total' => $total, 'places' => $places]);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->add('json', $name);
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->add('date', $name);
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->add('dateTime', $name);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->add('timestamp', $name);
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function softDeletes(string $name = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($name)->nullable();
    }

    /** Полиморфная пара `{name}_id` + `{name}_type`. */
    public function morphs(string $name): void
    {
        $this->unsignedBigInteger($name . '_id');
        $this->string($name . '_type');
        $this->index([$name . '_type', $name . '_id']);
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    public function index(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'index', 'columns' => (array) $columns, 'name' => $name];
    }

    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => (array) $columns, 'name' => $name];
    }

    public function primary(string|array $columns): void
    {
        $this->indexes[] = ['type' => 'primary', 'columns' => (array) $columns, 'name' => null];
    }

    public function dropColumn(string ...$columns): void
    {
        array_push($this->dropColumns, ...$columns);
    }
}
