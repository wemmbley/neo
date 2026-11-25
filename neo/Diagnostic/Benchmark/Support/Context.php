<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Support;

use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\Database\Query\Builder;

/**
 * Что нужно сценарию: соединение с БД, параметры запроса и случайные id существующих строк.
 * Границы id кэшируются в процессе воркера на bounds_ttl секунд, чтобы не добавлять запросы в каждый замер.
 */
final class Context
{
    /** @var array<string, array{0: int, 1: int}> */
    private static array $bounds = [];

    private static float $boundsAt = 0.0;

    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly DatabaseManager $db,
        private readonly array $params = [],
        private readonly int $boundsTtl = 5,
    ) {}

    public function connection(): Connection
    {
        return $this->db->connection();
    }

    public function table(string $table): Builder
    {
        return $this->connection()->table($table);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function int(string $key, int $default, int $min = 0, int $max = PHP_INT_MAX): int
    {
        return max($min, min($max, (int) ($this->params[$key] ?? $default)));
    }

    public function userId(): int
    {
        return $this->randomId('bench_users');
    }

    public function postId(): int
    {
        return $this->randomId('bench_posts');
    }

    public function maxId(string $table): int
    {
        return $this->bounds($table)[1];
    }

    private function randomId(string $table): int
    {
        [$min, $max] = $this->bounds($table);

        return random_int($min, $max);
    }

    /** @return array{0: int, 1: int} */
    private function bounds(string $table): array
    {
        $now = microtime(true);

        if ($now - self::$boundsAt > $this->boundsTtl) {
            self::$bounds = [];
            self::$boundsAt = $now;
        }

        if (!isset(self::$bounds[$table])) {
            $row = $this->connection()->selectOne("SELECT MIN(id) AS lo, MAX(id) AS hi FROM {$table}");
            $lo = (int) ($row['lo'] ?? 0);
            $hi = (int) ($row['hi'] ?? 0);

            if ($hi < 1) {
                throw new NoDataException("Таблица {$table} пуста: сначала выполните /benchmark/seed (или php cli benchmark:seed).");
            }

            self::$bounds[$table] = [$lo, $hi];
        }

        return self::$bounds[$table];
    }

    public static function forgetBounds(): void
    {
        self::$bounds = [];
        self::$boundsAt = 0.0;
    }
}
