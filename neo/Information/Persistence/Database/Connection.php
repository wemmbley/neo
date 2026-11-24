<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database;

use Closure;
use Neo\Information\Persistence\Database\Exceptions\QueryException;
use Neo\Information\Persistence\Database\Query\Builder;
use Neo\Information\Persistence\Database\Query\Grammar;
use Neo\Information\Persistence\Database\Query\Raw;
use Neo\Information\Persistence\Database\Schema\SchemaBuilder;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Одно соединение: PDO + грамматика + транзакции (с savepoint для вложенных).
 */
class Connection
{
    private int $transactions = 0;

    /** Связь с сервером потеряна: соединение нельзя возвращать в пул, его нужно закрыть. */
    private bool $broken = false;

    /** @var list<Closure(string, array, float): void> */
    private array $listeners = [];

    public function __construct(
        private readonly PDO $pdo,
        public readonly array $config,
        public readonly string $name,
        public readonly Grammar $grammar,
    ) {}

    public function driver(): string
    {
        return (string) $this->config['driver'];
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function table(string $table): Builder
    {
        return new Builder($this, $this->withPrefix($table));
    }

    public function schema(): SchemaBuilder
    {
        return new SchemaBuilder($this);
    }

    public function raw(string $value): Raw
    {
        return new Raw($value);
    }

    public function withPrefix(string $table): string
    {
        $prefix = (string) ($this->config['prefix'] ?? '');

        return $prefix !== '' && !str_starts_with($table, $prefix) ? $prefix . $table : $table;
    }

    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function isBroken(): bool
    {
        return $this->broken;
    }

    /**
     * Возвращает соединение в чистое состояние перед следующим запросом (пул, долгоживущий воркер):
     * откатывает незавершённую транзакцию и забывает слушателей запроса. Транзакция не может
     * пережить запрос.
     */
    public function reset(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (PDOException) {
            $this->broken = true;
        }

        $this->transactions = 0;
        $this->listeners = [];
    }

    /** Живое ли соединение: дешёвый ping для проверки после простоя в пуле. */
    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1')?->closeCursor();

            return true;
        } catch (PDOException) {
            $this->broken = true;

            return false;
        }
    }

    # ------------------------------------------------------------------ SQL

    /** @return array<int, array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static fn (PDOStatement $s) => $s->fetchAll());
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->run($sql, $bindings, static fn () => true);
    }

    public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, static fn (PDOStatement $s) => $s->rowCount());
    }

    public function insert(string $sql, array $bindings = []): bool
    {
        return $this->statement($sql, $bindings);
    }

    public function update(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    public function delete(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    public function unprepared(string $sql): bool
    {
        return $this->run($sql, [], fn () => $this->pdo->exec($sql) !== false, prepare: false);
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    private function run(string $sql, array $bindings, Closure $callback, bool $prepare = true): mixed
    {
        $start = microtime(true);

        try {
            if (!$prepare) {
                $result = $callback(null);
            } else {
                $stmt = $this->pdo->prepare($sql);
                $this->bind($stmt, $bindings);
                $stmt->execute();
                $result = $callback($stmt);
            }
        } catch (PDOException $e) {
            if ($this->lostConnection($e)) {
                $this->broken = true;
            }

            throw new QueryException($sql, $bindings, $e);
        }

        $ms = (microtime(true) - $start) * 1000;
        foreach ($this->listeners as $l) {
            $l($sql, $bindings, $ms);
        }

        return $result;
    }

    /** MySQL: 2006 «server has gone away», 2013 «lost connection»; ещё типичные тексты обрыва. */
    private function lostConnection(PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        if ($code === 2006 || $code === 2013) {
            return true;
        }

        $message = strtolower($e->getMessage());

        foreach (['server has gone away', 'lost connection', 'broken pipe', 'error while sending', 'is dead or not enabled'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function bind(PDOStatement $stmt, array $bindings): void
    {
        foreach (array_values($bindings) as $i => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $stmt->bindValue($i + 1, $value, $type);
        }
    }

    # ---------------------------------------------------------- transactions

    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT neo_trans_' . $this->transactions);
        }

        $this->transactions++;
    }

    public function commit(): void
    {
        if ($this->transactions === 0) {
            return;
        }

        $this->transactions--;

        if ($this->transactions === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT neo_trans_' . $this->transactions);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactions === 0) {
            return;
        }

        $this->transactions--;

        if ($this->transactions === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT neo_trans_' . $this->transactions);
        }
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /**
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        $this->commit();

        return $result;
    }
}
