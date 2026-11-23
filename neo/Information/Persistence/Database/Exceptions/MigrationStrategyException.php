<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Exceptions;

/** В файле миграции нет ни up(), ни table(). */
class MigrationStrategyException extends DatabaseException
{
    public function __construct(public readonly string $migrationFile)
    {
        parent::__construct("Миграция {$migrationFile} не объявляет ни up() (legacy), ни table() (декларативный поток).");
    }
}
