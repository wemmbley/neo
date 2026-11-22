<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Exceptions;

use RuntimeException;

class ModelNotFoundException extends RuntimeException
{
    /** @param class-string $model */
    public function __construct(public readonly string $model, public readonly mixed $key = null)
    {
        parent::__construct('Модель ' . $model . ($key !== null ? ' с ключом [' . (is_scalar($key) ? $key : json_encode($key)) . ']' : '') . ' не найдена.');
    }
}
