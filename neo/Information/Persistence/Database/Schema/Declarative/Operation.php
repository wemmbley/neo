<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

/**
 * Одна атомарная операция над схемой. Хранится в истории как массив (JSON), этого хватает для отката.
 *
 * Виды: createTable, dropTable, addColumn, dropColumn, changeColumn, renameColumn,
 *       addIndex, dropIndex, addForeign, dropForeign.
 */
final class Operation
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $kind, public readonly string $table, public readonly array $data = []) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $kind = (string) $a['op'];
        $table = (string) $a['table'];
        unset($a['op'], $a['table']);

        return new self($kind, $table, $a);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['op' => $this->kind, 'table' => $this->table] + $this->data;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** Разрушает данные или структуру: нужна явная подтверждающая реакция. */
    public function isDestructive(): bool
    {
        return match ($this->kind) {
            'dropTable', 'dropColumn', 'renameColumn' => true,
            'changeColumn' => ($this->data['from']['sql'] ?? null) !== ($this->data['to']['sql'] ?? null),
            default => false,
        };
    }

    public function describe(): string
    {
        $t = $this->table;

        return match ($this->kind) {
            'createTable' => "создать таблицу {$t} (" . implode(', ', array_keys($this->data['def']['columns'] ?? [])) . ')',
            'dropTable' => "УДАЛИТЬ таблицу {$t}",
            'addColumn' => "добавить колонку {$t}.{$this->data['column']} ({$this->data['def']['sql']})",
            'dropColumn' => "УДАЛИТЬ колонку {$t}.{$this->data['column']}",
            'changeColumn' => "изменить колонку {$t}.{$this->data['column']}: "
                . self::short($this->data['from']) . ' → ' . self::short($this->data['to']),
            'renameColumn' => "ПЕРЕИМЕНОВАТЬ колонку {$t}.{$this->data['from']} → {$this->data['to']}",
            'addIndex' => "добавить индекс {$t}.{$this->data['name']} (" . implode(', ', $this->data['index']['columns']) . ')',
            'dropIndex' => "удалить индекс {$t}.{$this->data['name']}",
            'addForeign' => "добавить внешний ключ {$t}.{$this->data['fk']['column']} → {$this->data['fk']['on']}.{$this->data['fk']['references']}",
            'dropForeign' => "удалить внешний ключ {$t}.{$this->data['fk']['column']}",
            default => $this->kind,
        };
    }

    /** @param array<string, mixed> $d */
    private static function short(array $d): string
    {
        return $d['sql'] . ($d['nullable'] ? ' null' : ' not null')
            . (($d['default'] ?? null) !== null ? ' default ' . $d['default'] : '');
    }

    /**
     * Обратная операция для отката. Второй элемент: предупреждение, если откат неточный.
     *
     * @return array{0: self, 1: ?string}
     */
    public function inverse(): array
    {
        $t = $this->table;
        $d = $this->data;

        switch ($this->kind) {
            case 'createTable':
                return [new self('dropTable', $t, ['def' => $d['def']]), null];
            case 'dropTable':
                return [new self('createTable', $t, ['def' => $d['def']]), null];
            case 'addColumn':
                return [new self('dropColumn', $t, ['column' => $d['column'], 'def' => $d['def']]), null];
            case 'dropColumn':
                $def = $d['def'];
                $warn = null;
                if (!$def['nullable'] && ($def['default'] ?? null) === null && empty($def['auto'])) {
                    $def['nullable'] = true;
                    $warn = "колонка {$t}.{$d['column']} NOT NULL без значения по умолчанию восстановлена как NULL: данные не вернуть.";
                }

                return [new self('addColumn', $t, ['column' => $d['column'], 'def' => $def]), $warn];
            case 'changeColumn':
                return [new self('changeColumn', $t, ['column' => $d['column'], 'from' => $d['to'], 'to' => $d['from']]), null];
            case 'renameColumn':
                return [new self('renameColumn', $t, ['from' => $d['to'], 'to' => $d['from'], 'def' => $d['def']]), null];
            case 'addIndex':
                return [new self('dropIndex', $t, ['name' => $d['name'], 'index' => $d['index']]), null];
            case 'dropIndex':
                return [new self('addIndex', $t, ['name' => $d['name'], 'index' => $d['index']]), null];
            case 'addForeign':
                return [new self('dropForeign', $t, ['name' => $d['name'], 'fk' => $d['fk']]), null];
            case 'dropForeign':
                return [new self('addForeign', $t, ['name' => $d['name'], 'fk' => $d['fk']]), null];
        }

        throw new \LogicException("Нет обратной операции для {$this->kind}");
    }
}
