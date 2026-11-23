<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

use Closure;
use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\Exceptions\ConfirmationRequiredException;
use Neo\Information\Persistence\Database\Exceptions\DatabaseException;
use Neo\Information\Persistence\Database\Exceptions\SchemaConflictException;
use Neo\Information\Persistence\Database\Migrations\CommitRepository;
use Neo\Information\Persistence\Database\Migrations\Migrator;

/**
 * Декларативные миграции: файл с table() описывает желаемое состояние, Neo считает разницу
 * с последним закоммиченным состоянием файла (или с реальной таблицей при первом коммите),
 * применяет её и пишет коммит в `migrations_commits`.
 */
class SchemaMigrator
{
    private ?CommitRepository $commits = null;

    public function __construct(private readonly Migrator $migrator) {}

    private function conn(): Connection
    {
        return $this->migrator->connection();
    }

    public function commits(): CommitRepository
    {
        return $this->commits ??= new CommitRepository($this->conn(), (string) $this->migrator->config('commits_table', 'migrations_commits'));
    }

    private function ddl(): DdlBuilder
    {
        return new DdlBuilder($this->conn()->driver(), $this->conn()->grammar);
    }

    /* ---------------------------------------------------------------- планы */

    /** @param (Closure(string, string, string): bool)|null $askRename */
    public function plan(?Closure $askRename = null): SchemaPlan
    {
        $conn = $this->conn();
        $intro = new Introspector($conn);
        $cmp = new SchemaComparator($askRename);

        $contributions = $hashes = $entries = [];
        foreach ($this->migrator->declarative() as $e) {
            $key = $e['owner'] . '::' . $e['name'];

            if (isset($entries[$key])) {
                throw new SchemaConflictException("Миграция {$key} найдена дважды.");
            }

            $entries[$key] = $e;
            $hashes[$key] = (string) sha1_file($e['file']);
            $contributions[$key] = Recorder::contribution($e['migration'], $conn);
        }

        $registry = new SchemaRegistry($contributions);
        $heads = $this->commits()->heads();

        $headCreated = [];
        foreach ($heads as $h) {
            foreach ($h['snapshot']['tables'] ?? [] as $t => $d) {
                if (($d['mode'] ?? '') === 'create') {
                    $headCreated[$t] = true;
                }
            }
        }

        $items = $creates = $rest = $warnings = [];

        foreach ($contributions as $key => $contribution) {
            $head = $heads[$key] ?? null;

            if ($head !== null && $head['hash'] === $hashes[$key]) {
                continue;
            }

            $old = $head['snapshot']['tables'] ?? [];
            $ops = [];

            foreach ($contribution['tables'] as $table => $def) {
                $new = SchemaComparator::definition($def);

                if (isset($old[$table])) {
                    $ops = [...$ops, ...$cmp->diff($table, SchemaComparator::definition($old[$table]), $new)];
                } elseif ($intro->exists($table)) {
                    $physical = $intro->table($table);
                    $ops = [...$ops, ...$cmp->diff($table, $physical, $new, true)];

                    $hints = array_filter(array_map(static fn (array $c) => $c['renamedFrom'] ?? null, $def['columns']));
                    $extras = array_diff(array_keys($physical['columns']), $registry->columnsOf($table), $hints);
                    if ($extras !== []) {
                        $warnings[] = "Таблица {$table} уже существует, колонки вне деклараций оставлены как есть: " . implode(', ', $extras) . '.';
                    }
                } elseif ($def['mode'] === 'create') {
                    $ops[] = $cmp->createTable($table, $new);
                } else {
                    if ($registry->creator($table) === null && !isset($headCreated[$table])) {
                        throw new SchemaConflictException(
                            "Файл {$key} расширяет таблицу [{$table}], но её никто не создаёт и в БД её нет."
                        );
                    }
                    $ops = [...$ops, ...$cmp->diff($table, SchemaComparator::emptyTable(), $new)];
                }
            }

            foreach ($old as $table => $oldDef) {
                if (!isset($contribution['tables'][$table])) {
                    $this->assertNobodyElseUses($table, $key, $contributions, $heads);
                    $ops[] = $cmp->dropTable($table, $oldDef);
                }
            }

            $items[] = new PlanItem(
                $entries[$key]['owner'], $entries[$key]['name'], 'apply', $hashes[$key], $contribution, $ops,
                $head === null ? 'новая' : 'изменена',
            );

            foreach ($ops as $op) {
                $op->kind === 'createTable' ? $creates[] = $op : $rest[] = $op;
            }
        }

        return new SchemaPlan($items, [...$this->orderCreates($creates), ...$this->groupByTable($rest)], $warnings);
    }

    /** Файлы, которых больше нет, но их вклад остался в БД. @return list<array{key: string, row: array}> */
    public function orphans(): array
    {
        $present = [];
        foreach ($this->migrator->declarative() as $e) {
            $present[$e['owner'] . '::' . $e['name']] = true;
        }

        $out = [];
        foreach ($this->commits()->heads() as $key => $row) {
            if (!isset($present[$key]) && ($row['snapshot']['tables'] ?? []) !== []) {
                $out[] = ['key' => $key, 'row' => $row];
            }
        }

        return $out;
    }

    /** Удаляет из БД всё, что осталось от пропавших файлов. */
    public function purgePlan(): SchemaPlan
    {
        $orphans = $this->orphans();
        $intro = new Introspector($this->conn());
        $cmp = new SchemaComparator();
        $heads = $this->commits()->heads();
        $orphanKeys = array_column($orphans, 'key');

        $items = $sequence = [];

        foreach ($orphans as ['key' => $key, 'row' => $row]) {
            $ops = [];

            foreach ($row['snapshot']['tables'] as $table => $def) {
                if (!$intro->exists($table)) {
                    continue;
                }

                if ($def['mode'] === 'create') {
                    $this->assertNobodyElseUses($table, $key, [], $heads, $orphanKeys);
                    $ops[] = $cmp->dropTable($table, $def);
                } else {
                    $ops = [...$ops, ...$cmp->diff($table, SchemaComparator::definition($def), SchemaComparator::emptyTable())];
                }
            }

            $items[] = new PlanItem($row['owner'], $row['migration'], 'purge', '', ['tables' => []], $ops, 'орфан');
            $sequence = [...$sequence, ...$ops];
        }

        return new SchemaPlan($items, $sequence);
    }

    /** Возвращает структуру к состоянию на момент коммита $toId (reset-подобный откат). */
    public function rollbackPlan(int $toId, ?string $file = null): SchemaPlan
    {
        $rows = $this->commits()->all();

        $match = static fn (array $r): bool => $file === null || $file === $r['migration'] || $file === $r['owner'] . '::' . $r['migration'];

        if ($file !== null && array_filter($rows, $match) === []) {
            throw new DatabaseException("В истории нет миграции [{$file}].");
        }

        $targets = array_filter($rows, static fn (array $r) => $r['id'] > $toId && $match($r));
        usort($targets, static fn (array $a, array $b) => $b['id'] <=> $a['id']);

        $sequence = $perKey = $warnings = [];

        foreach ($targets as $row) {
            $key = $row['owner'] . '::' . $row['migration'];

            foreach (array_reverse($row['operations']) as $raw) {
                [$inverse, $warning] = Operation::fromArray($raw)->inverse();
                $sequence[] = $inverse;
                $perKey[$key][] = $inverse;
                $warning !== null && $warnings[] = $warning;
            }
            $perKey[$key] ??= [];
        }

        $items = [];
        foreach ($perKey as $key => $ops) {
            [$owner, $name] = explode('::', $key, 2);
            $restore = null;
            foreach ($rows as $r) {
                if ($r['owner'] === $owner && $r['migration'] === $name && $r['id'] <= $toId) {
                    $restore = $r;
                }
            }

            $items[] = new PlanItem($owner, $name, 'rollback', $restore['hash'] ?? '', $restore['snapshot'] ?? ['tables' => []], $ops, "откат к #{$toId}");
        }

        return new SchemaPlan($items, $sequence, $warnings);
    }

    /* ----------------------------------------------------------- выполнение */

    /**
     * @param (Closure(list<Operation>): bool)|null $confirm спросить подтверждение; null: не интерактивно
     * @param (Closure(string): void)|null $report
     * @return bool false: пользователь отказался
     */
    public function execute(SchemaPlan $plan, bool $yes = false, ?Closure $confirm = null, ?Closure $report = null): bool
    {
        if ($plan->isEmpty()) {
            return true;
        }

        $destructive = $plan->destructive();

        if ($destructive !== [] && !$yes) {
            if ($confirm === null) {
                throw new ConfirmationRequiredException(
                    "План содержит разрушающие операции:\n  - "
                    . implode("\n  - ", array_map(static fn (Operation $o) => $o->describe(), $destructive))
                    . "\nЗапустите команду в терминале и подтвердите, либо передайте --yes."
                );
            }
            if (!$confirm($destructive)) {
                return false;
            }
        }

        $conn = $this->conn();
        $repo = $this->commits();
        $repo->ensureTable();

        $run = function () use ($plan, $repo, $report): void {
            $this->applySequence($plan->sequence, $report);

            foreach ($plan->items as $item) {
                $id = $repo->record(
                    $item->owner, $item->name, $item->action, $item->hash, $item->snapshot,
                    array_map(static fn (Operation $o) => $o->toArray(), $item->operations),
                );
                $report && $report("#{$id} {$item->key()} [{$item->action}]");
            }
        };

        if ($this->ddl()->mysql()) {
            # DDL в MySQL неявно коммитится, откатить частично применённый план нельзя.
            $run();

            return true;
        }

        $conn->unprepared('PRAGMA foreign_keys = OFF');
        try {
            $conn->transaction($run);
        } finally {
            $conn->unprepared('PRAGMA foreign_keys = ON');
        }

        return true;
    }

    /** SQL плана без выполнения. @return list<string> */
    public function pretend(SchemaPlan $plan): array
    {
        $ddl = $this->ddl();
        $out = [];

        foreach ($this->segments($plan->sequence) as [$table, $ops, $rebuild]) {
            if ($rebuild) {
                $out[] = "-- sqlite: таблица {$table} будет пересобрана (создать новую, перенести данные, подменить):";
                foreach ($ops as $op) {
                    $out[] = '--   ' . $op->describe();
                }
                continue;
            }
            foreach ($ops as $op) {
                foreach ($ddl->statements($op) as $sql) {
                    $out[] = $sql . ';';
                }
            }
        }

        return $out;
    }

    /** @param list<Operation> $sequence */
    private function applySequence(array $sequence, ?Closure $report): void
    {
        $conn = $this->conn();
        $ddl = $this->ddl();
        $rebuilder = new SqliteRebuilder($conn, $ddl, new Introspector($conn));
        $rebuilt = false;

        foreach ($this->segments($sequence) as [$table, $ops, $rebuild]) {
            if ($rebuild) {
                $rebuilder->rebuild($table, $ops);
                $rebuilt = true;
                continue;
            }
            foreach ($ops as $op) {
                foreach ($ddl->statements($op) as $sql) {
                    $conn->statement($sql);
                }
            }
        }

        if ($rebuilt) {
            $violations = $conn->select('pragma foreign_key_check');
            if ($violations !== []) {
                $v = $violations[0];
                throw new DatabaseException(
                    "После пересборки нарушена целостность внешних ключей (таблица {$v['table']}, строка {$v['rowid']} → {$v['parent']}). Изменения отменены."
                );
            }
        }
    }

    /**
     * Делит операции на сегменты: подряд идущие операции одной таблицы; create/drop таблицы стоят отдельно.
     * Для sqlite сегмент пересобирается целиком, если в нём есть операция, которую нельзя выразить ALTER.
     *
     * @param list<Operation> $sequence
     * @return list<array{0: string, 1: list<Operation>, 2: bool}>
     */
    private function segments(array $sequence): array
    {
        $ddl = $this->ddl();
        $segments = [];
        $current = null;

        foreach ($sequence as $op) {
            $tableLevel = in_array($op->kind, ['createTable', 'dropTable'], true);

            if ($tableLevel) {
                $current = null;
                $segments[] = [$op->table, [$op], false];
                continue;
            }

            if ($current === null || $segments[$current][0] !== $op->table) {
                $segments[] = [$op->table, [], false];
                $current = array_key_last($segments);
            }

            $segments[$current][1][] = $op;
            $segments[$current][2] = $segments[$current][2] || $ddl->needsRebuild($op);
        }

        return $segments;
    }

    /* ---------------------------------------------------------------- отчёты */

    /** @return array{files: list<array>, orphans: list<array>} */
    public function status(): array
    {
        $heads = $this->commits()->heads();
        $files = [];

        foreach ($this->migrator->declarative() as $e) {
            $key = $e['owner'] . '::' . $e['name'];
            $head = $heads[$key] ?? null;
            $hash = (string) sha1_file($e['file']);

            $files[] = [
                'owner' => $e['owner'],
                'name' => $e['name'],
                'state' => $head === null ? 'new' : ($head['hash'] === $hash ? 'applied' : 'changed'),
                'commit' => $head['id'] ?? null,
            ];
        }

        return [
            'files' => $files,
            'orphans' => array_map(static fn (array $o) => [
                'owner' => $o['row']['owner'], 'name' => $o['row']['migration'], 'commit' => $o['row']['id'],
            ], $this->orphans()),
        ];
    }

    /** @return list<array{id: int, owner: string, migration: string, action: string, created_at: string, operations: list<Operation>}> */
    public function log(): array
    {
        return array_map(static fn (array $r) => [
            'id' => $r['id'],
            'owner' => $r['owner'],
            'migration' => $r['migration'],
            'action' => $r['action'],
            'created_at' => $r['created_at'],
            'operations' => array_map(Operation::fromArray(...), $r['operations']),
        ], $this->commits()->all());
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * @param array<string, array{tables: array}> $contributions
     * @param array<string, array> $heads
     * @param list<string> $ignore
     */
    private function assertNobodyElseUses(string $table, string $key, array $contributions, array $heads, array $ignore = []): void
    {
        foreach ($contributions as $other => $c) {
            if ($other !== $key && isset($c['tables'][$table])) {
                throw new SchemaConflictException("Нельзя удалить таблицу [{$table}] из {$key}: в ней есть вклад {$other}.");
            }
        }

        foreach ($heads as $other => $h) {
            if ($other !== $key && !in_array($other, $ignore, true) && isset($h['snapshot']['tables'][$table])) {
                throw new SchemaConflictException("Нельзя удалить таблицу [{$table}] из {$key}: в ней есть вклад {$other}.");
            }
        }
    }

    /**
     * Создания таблиц идут первыми (фаза 1), сначала те, на которые ссылаются внешние ключи.
     *
     * @param list<Operation> $creates
     * @return list<Operation>
     */
    private function orderCreates(array $creates): array
    {
        $byTable = [];
        foreach ($creates as $op) {
            $byTable[$op->table] = $op;
        }

        $ordered = $visiting = [];
        $visit = function (string $table) use (&$visit, &$ordered, &$visiting, $byTable): void {
            if (isset($ordered[$table]) || isset($visiting[$table])) {
                return;
            }
            $visiting[$table] = true;
            foreach ($byTable[$table]->get('def')['foreign'] as $fk) {
                if ($fk['on'] !== $table && isset($byTable[$fk['on']])) {
                    $visit($fk['on']);
                }
            }
            $ordered[$table] = $byTable[$table];
        };

        foreach (array_keys($byTable) as $table) {
            $visit($table);
        }

        return array_values($ordered);
    }

    /**
     * @param list<Operation> $ops
     * @return list<Operation>
     */
    private function groupByTable(array $ops): array
    {
        $grouped = [];
        foreach ($ops as $op) {
            $grouped[$op->table][] = $op;
        }

        return array_merge(...array_values($grouped ?: [[]]));
    }
}
