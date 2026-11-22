<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Exceptions;

use LogicException;

class ImmutableAttributeException extends LogicException
{
    public function __construct(string $model, string $property)
    {
        parent::__construct("Свойство {$model}::\${$property} помечено #[Immutable] и не может меняться после сохранения.");
    }
}
