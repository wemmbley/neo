<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Exceptions;

/** В одном файле миграции объявлены и up() (legacy), и table() (декларативный поток). */
class DoubleStrategyException extends DatabaseException
{
    public function __construct(public readonly string $migrationFile)
    {
        parent::__construct(
            "Миграция {$migrationFile} объявляет и up() (legacy), и table() (декларативный поток). Оставьте один метод."
        );
    }
}
