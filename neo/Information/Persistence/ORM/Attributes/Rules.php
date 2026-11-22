<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Правила валидации и сообщения (для Validator::). */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Rules
{
    /**
     * @param list<string|object>   $rules
     * @param array<string, string> $messages
     */
    public function __construct(
        public readonly array $rules = [],
        public readonly array $messages = [],
    ) {}
}
