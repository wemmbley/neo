<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Exceptions;

use LogicException;

class LazyLoadingViolationException extends LogicException
{
    public function __construct(public readonly string $model, public readonly string $relation)
    {
        parent::__construct(
            "Ленивая загрузка отношения [{$relation}] у {$model} запрещена (N+1). Добавьте ->with('{$relation}') в запрос или ->load('{$relation}') к моделям."
        );
    }
}
