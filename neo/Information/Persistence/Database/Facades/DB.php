<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Facades;

use Closure;
use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\Database\Query\Builder;
use Neo\Information\Persistence\Database\Query\Raw;
use Neo\Information\Persistence\Database\Schema\SchemaBuilder;

/**
 * @method static Connection connection(?string $name = null)
 * @method static string getDefaultConnection()
 * @method static void setDefaultConnection(string $name)
 * @method static void purge(?string $name = null)
 * @method static void extend(string $name, array $config)
 * @method static Builder table(string $table)
 * @method static SchemaBuilder schema(?string $connection = null)
 * @method static Raw raw(string $value)
 * @method static array select(string $sql, array $bindings = [])
 * @method static array|null selectOne(string $sql, array $bindings = [])
 * @method static bool statement(string $sql, array $bindings = [])
 * @method static bool insert(string $sql, array $bindings = [])
 * @method static int update(string $sql, array $bindings = [])
 * @method static int delete(string $sql, array $bindings = [])
 * @method static int affectingStatement(string $sql, array $bindings = [])
 * @method static bool unprepared(string $sql)
 * @method static string lastInsertId()
 * @method static mixed transaction(Closure $callback, ?int $attempts = null)
 * @method static void beginTransaction()
 * @method static void commit()
 * @method static void rollBack()
 * @method static int transactionLevel()
 * @method static void listen(Closure $listener)
 *
 * @see DatabaseManager
 */
#[FacadeOf(DatabaseManager::class)]
final class DB extends Facade {}