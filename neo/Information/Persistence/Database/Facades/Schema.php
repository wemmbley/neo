<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Facades;

use Closure;
use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Information\Persistence\Database\Schema\SchemaBuilder;

/**
 * @method static void create(string $table, Closure $callback)
 * @method static void table(string $table, Closure $callback)
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void rename(string $from, string $to)
 * @method static bool hasTable(string $table)
 * @method static bool hasColumn(string $table, string $column)
 * @method static array getColumnListing(string $table)
 * @method static void disableForeignKeyConstraints()
 * @method static void enableForeignKeyConstraints()
 *
 * @see SchemaBuilder
 */
#[FacadeOf(SchemaBuilder::class)]
final class Schema extends Facade {}