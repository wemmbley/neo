<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Contracts;

use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;

interface InlineSyntaxContract extends SyntaxContract
{
    /**
     * Символы, с которых может начинаться фрагмент: `*_` для курсива.
     */
    public static function triggers(): string;

    /**
     * Вызывается, когда в $position стоит один из triggers().
     * Вернуть Hit(сколько байт занято, атрибуты для Transfer) либо null.
     */
    public static function matchInline(string $text, int $position, Context $context): ?Hit;
}
