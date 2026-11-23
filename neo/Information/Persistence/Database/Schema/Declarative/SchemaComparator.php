<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

use Closure;
use Neo\Information\Persistence\Database\Exceptions\ConfirmationRequiredException;
use Neo\Information\Persistence\Database\Exceptions\UnsupportedSchemaChangeException;

/**
 * Считает разницу между двумя состояниями таблицы и возвращает список операций.
 *
 * Режим $physical: «старое» состояние прочитано из реальной таблицы (первый коммит или переход с legacy).
 * Тогда только добавляем и меняем: лишнее в БД может принадлежать другим модулям, его не трогаем.
 */
final class SchemaComparator
{
    /** @param (Closure(string, string, string): bool)|null $askRename fn(table, old, new): это переименование? null = не интерактивно */
    public function __construct(private readonly ?Closure $askRename = null) {}

    /** @return array{columns: array, indexes: array, foreign: array, primary: list<string>} */
    public static function emptyTable(): array
    {
        return ['columns' => [], 'indexes' => [], 'foreign' => [], 'primary' => []];
    }

    /** @return array{columns: array, indexes: array, foreign: array, primary: list<string>} */
    public static function definition(array $table): array
    {
        return [
            'columns' => $table['columns'],
            'indexes' => $table['indexes'],
            'foreign' => $table['foreign'],
            'primary' => $table['primary'],
        ];
    }

    public function createTable(string $table, array $new): Operation
    {
        return new Operation('createTable', $table, ['def' => self::definition($new)]);
    }

    public function dropTable(string $table, array $old): Operation
    {
        return new Operation('dropTable', $table, ['def' => self::definition($old)]);
    }

    /** @return list<Operation> */
    public function diff(string $table, array $old, array $new, bool $physical = false): array
    {
        if (!$physical && ($old['primary'] ?? []) !== [] && $old['primary'] !== $new['primary']) {
            throw new UnsupportedSchemaChangeException(
                "Таблица {$table}: смена первичного ключа (" . implode(',', $old['primary']) . ' → ' . implode(',', $new['primary'])
                . ') не поддерживается декларативным потоком.'
            );
        }

        $renames = $this->renames($table, $old['columns'], $new['columns'], $physical);

        $renameOld = array_flip($renames);          # новое имя => старое
        $oldColumns = $old['columns'];
        $drop = $add = $change = $rename = [];

        foreach ($new['columns'] as $name => $def) {
            if (isset($renameOld[$name])) {
                $oldName = $renameOld[$name];
                $rename[] = new Operation('renameColumn', $table, ['from' => $oldName, 'to' => $name, 'def' => $oldColumns[$oldName]]);
                if (SchemaSnapshot::columnSignature($oldColumns[$oldName]) !== SchemaSnapshot::columnSignature($def)) {
                    $change[] = new Operation('changeColumn', $table, ['column' => $name, 'from' => $oldColumns[$oldName], 'to' => $def]);
                }
            } elseif (!isset($oldColumns[$name])) {
                $add[] = new Operation('addColumn', $table, ['column' => $name, 'def' => $def]);
            } elseif (SchemaSnapshot::columnSignature($oldColumns[$name]) !== SchemaSnapshot::columnSignature($def)) {
                $change[] = new Operation('changeColumn', $table, ['column' => $name, 'from' => $oldColumns[$name], 'to' => $def]);
            }
        }

        if (!$physical) {
            foreach ($oldColumns as $name => $def) {
                if (!isset($new['columns'][$name]) && !isset($renames[$name])) {
                    $drop[] = new Operation('dropColumn', $table, ['column' => $name, 'def' => $def]);
                }
            }
        }

        # Индексы и внешние ключи сравниваются по форме (не по имени); старые подтягиваем под переименования колонок.
        $swap = static fn (array $cols): array => array_map(static fn (string $c) => $renames[$c] ?? $c, $cols);

        $oldIdx = [];
        foreach ($old['indexes'] as $n => $i) {
            $i['columns'] = $swap($i['columns']);
            $oldIdx[SchemaSnapshot::indexSignature($i)] = [$n, $i];
        }
        $newIdx = [];
        foreach ($new['indexes'] as $n => $i) {
            $newIdx[SchemaSnapshot::indexSignature($i)] = [$n, $i];
        }

        $oldFk = [];
        foreach ($old['foreign'] as $n => $f) {
            $f['column'] = $renames[$f['column']] ?? $f['column'];
            $oldFk[SchemaSnapshot::foreignSignature($f)] = [$n, $f];
        }
        $newFk = [];
        foreach ($new['foreign'] as $n => $f) {
            $newFk[SchemaSnapshot::foreignSignature($f)] = [$n, $f];
        }

        $dropFk = $dropIdx = $addIdx = $addFk = [];

        if (!$physical) {
            foreach (array_diff_key($oldFk, $newFk) as [$n, $f]) {
                # Внешний ключ удалённой колонки исчезнет вместе с ней.
                $dropFk[] = new Operation('dropForeign', $table, ['name' => $n, 'fk' => $f]);
            }
            foreach (array_diff_key($oldIdx, $newIdx) as [$n, $i]) {
                $dropIdx[] = new Operation('dropIndex', $table, ['name' => $n, 'index' => $i]);
            }
        }
        foreach (array_diff_key($newIdx, $oldIdx) as [$n, $i]) {
            $addIdx[] = new Operation('addIndex', $table, ['name' => $n, 'index' => $i]);
        }
        foreach (array_diff_key($newFk, $oldFk) as [$n, $f]) {
            $addFk[] = new Operation('addForeign', $table, ['name' => $n, 'fk' => $f]);
        }

        return [...$dropFk, ...$dropIdx, ...$rename, ...$change, ...$drop, ...$add, ...$addIdx, ...$addFk];
    }

    /**
     * Какие старые колонки стали новыми: старое имя => новое.
     *
     * @return array<string, string>
     */
    private function renames(string $table, array $old, array $new, bool $physical): array
    {
        $removed = array_diff_key($old, $new);
        $added = array_diff_key($new, $old);
        $renames = [];

        foreach ($added as $name => $def) {
            $hint = $def['renamedFrom'] ?? null;
            if ($hint !== null && isset($removed[$hint]) && !isset($renames[$hint])) {
                $renames[$hint] = $name;
                unset($removed[$hint], $added[$name]);
            }
        }

        if ($physical || $removed === [] || $added === []) {
            return $renames;
        }

        $pairs = [];
        foreach ($removed as $o => $od) {
            foreach ($added as $n => $nd) {
                if ($od['sql'] === $nd['sql']) {
                    $pairs[] = [$o, $n];
                }
            }
        }

        if ($pairs === []) {
            return $renames;
        }

        if (count($removed) === 1 && count($added) === 1) {
            [$o, $n] = $pairs[0];

            if ($this->askRename === null) {
                throw new ConfirmationRequiredException(
                    "Таблица {$table}: колонка {$o} исчезла, а {$n} появилась. Это переименование или удаление с добавлением? "
                    . "Укажите ->renamedFrom('{$o}') у новой колонки или запустите команду в терминале и ответьте на вопрос."
                );
            }

            if (($this->askRename)($table, $o, $n)) {
                $renames[$o] = $n;
            }

            return $renames;
        }

        $hint = implode(', ', array_map(static fn (array $p) => "{$p[0]} → {$p[1]}", $pairs));
        throw new ConfirmationRequiredException(
            "Таблица {$table}: неоднозначные переименования ({$hint}). Укажите ->renamedFrom('старое_имя') у нужных колонок."
        );
    }
}
