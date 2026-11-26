<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

/**
 * Результат успешного совпадения синтаксиса.
 */
final readonly class Hit
{
    /**
     * @param int                  $length     блок: число строк; inline: число байт
     * @param array<string, mixed> $attributes данные для Transfer синтаксиса
     */
    public function __construct(
        public int $length,
        public array $attributes = [],
    ) {}
}
