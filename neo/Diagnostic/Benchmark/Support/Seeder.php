<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Support;

use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\DatabaseManager;

/** Наполнение и очистка bench_* таблиц. Данные дешёвые и случайные: цель создать объём, а не реализм. */
final class Seeder
{
    private const TABLES = ['bench_comments', 'bench_posts', 'bench_users', 'bench_events', 'bench_counters'];

    private const WORDS = ['alpha', 'swoole', 'neo', 'router', 'blade', 'pool', 'queue', 'mysql', 'index', 'cache',
        'latency', 'worker', 'session', 'cookie', 'kernel', 'bench', 'storm', 'fiber', 'socket', 'event'];

    private const STATUSES = ['published', 'published', 'published', 'draft', 'archived'];

    private const CHUNK = 500;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Добавляет строки к уже существующим (можно вызывать повторно, чтобы наращивать объём).
     *
     * @return array<string, array{rows: int, ms: float}>
     */
    public function seed(int $users, int $posts, int $comments): array
    {
        $c = $this->db->connection();
        $report = [];

        $report['bench_counters'] = $this->counters($c);

        $from = $this->maxId($c, 'bench_users');
        $report['bench_users'] = $this->timed(fn (): int => $this->fill($c, 'bench_users', $users, fn (int $i): array => [
            'name' => $this->words(2),
            'email' => 'user' . ($from + $i) . '.' . bin2hex(random_bytes(3)) . '@bench.test',
            'status' => $i % 20 === 0 ? 'blocked' : 'active',
            'balance' => random_int(0, 100000),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]));

        [$userLo, $userHi] = $this->range($c, 'bench_users');

        if ($userHi < 1) {
            return $report;
        }

        $report['bench_posts'] = $this->timed(fn (): int => $this->fill($c, 'bench_posts', $posts, fn (): array => [
            'user_id' => random_int($userLo, $userHi),
            'title' => ucfirst($this->words(random_int(3, 7))),
            'body' => $this->words(random_int(30, 80)),
            'status' => self::STATUSES[array_rand(self::STATUSES)],
            'views' => random_int(0, 5000),
            'score' => random_int(-50, 500),
            'published_at' => date('Y-m-d H:i:s', time() - random_int(0, 86400 * 365)),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]));

        [$postLo, $postHi] = $this->range($c, 'bench_posts');

        if ($postHi < 1) {
            return $report;
        }

        $report['bench_comments'] = $this->timed(fn (): int => $this->fill($c, 'bench_comments', $comments, fn (): array => [
            'post_id' => random_int($postLo, $postHi),
            'user_id' => random_int($userLo, $userHi),
            'body' => $this->words(random_int(5, 25)),
            'created_at' => date('Y-m-d H:i:s', time() - random_int(0, 86400 * 90)),
            'updated_at' => $this->now(),
        ]));

        Context::forgetBounds();

        return $report;
    }

    /** Очищает все bench_* таблицы. */
    public function reset(): array
    {
        $c = $this->db->connection();
        $cleared = [];

        foreach (self::TABLES as $table) {
            if (!$c->schema()->hasTable($table)) {
                continue;
            }

            $cleared[$table] = $c->table($table)->count();

            if ($c->driver() === 'mysql') {
                $c->unprepared("TRUNCATE TABLE {$table}");
            } else {
                $c->unprepared("DELETE FROM {$table}");

                try {
                    $c->unprepared("DELETE FROM sqlite_sequence WHERE name = '{$table}'");
                } catch (\Throwable) {
                    # sqlite_sequence есть не всегда
                }
            }
        }

        Context::forgetBounds();

        return $cleared;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $c = $this->db->connection();
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = $c->schema()->hasTable($table) ? $c->table($table)->count() : -1;
        }

        return $counts;
    }

    /** @return array{rows: int, ms: float} */
    private function counters(Connection $c): array
    {
        return $this->timed(function () use ($c): int {
            $names = ['hot', ...array_map(static fn (int $i): string => "shard_{$i}", range(0, 15))];
            $have = array_column($c->table('bench_counters')->get(), 'name');
            $rows = [];

            foreach ($names as $name) {
                if (!in_array($name, $have, true)) {
                    $rows[] = ['name' => $name, 'value' => 0];
                }
            }

            $rows !== [] && $c->table('bench_counters')->insert($rows);

            return count($rows);
        });
    }

    /** @param callable(int): array<string, mixed> $row */
    private function fill(Connection $c, string $table, int $total, callable $row): int
    {
        $done = 0;

        while ($done < $total) {
            $batch = [];
            $size = min(self::CHUNK, $total - $done);

            for ($i = 0; $i < $size; $i++) {
                $batch[] = $row($done + $i + 1);
            }

            $c->transaction(static fn () => $c->table($table)->insert($batch));
            $done += $size;
        }

        return $done;
    }

    /** @return array{rows: int, ms: float} */
    private function timed(callable $work): array
    {
        $t = hrtime(true);
        $rows = $work();

        return ['rows' => $rows, 'ms' => round((hrtime(true) - $t) / 1e6, 1)];
    }

    private function maxId(Connection $c, string $table): int
    {
        return (int) ($c->selectOne("SELECT MAX(id) AS hi FROM {$table}")['hi'] ?? 0);
    }

    /** @return array{0: int, 1: int} */
    private function range(Connection $c, string $table): array
    {
        $row = $c->selectOne("SELECT MIN(id) AS lo, MAX(id) AS hi FROM {$table}");

        return [(int) ($row['lo'] ?? 0), (int) ($row['hi'] ?? 0)];
    }

    private function words(int $count): string
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = self::WORDS[array_rand(self::WORDS)];
        }

        return implode(' ', $out);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
