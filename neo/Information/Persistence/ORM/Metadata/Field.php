<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Metadata;

/**
 * Описание одного публичного свойства модели (собирается по атрибутам и типу).
 */
final class Field
{
    /**
     * @param list<string|object>   $rules
     * @param array<string, string> $messages
     */
    public function __construct(
        public readonly string $property,
        public readonly string $column,
        public readonly ?string $cast,
        public readonly bool $nullable,
        public readonly bool $fillable,
        public readonly bool $hidden,
        public readonly bool $immutable,
        public readonly bool $primary,
        public readonly bool $autoIncrement,
        public readonly mixed $default,
        public readonly bool $hasDefault,
        public readonly array $rules,
        public readonly array $messages,
        public readonly ?string $watch,
    ) {}
}
