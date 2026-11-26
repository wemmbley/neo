<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Contracts\InlineSyntaxContract;
use Neo\Information\Markdown\Contracts\SyntaxContract;
use Neo\Transfer\Transfer;

/**
 * Зарегистрированная связка: имя → синтаксис → Transfer → blade-шаблон.
 */
final readonly class SyntaxDefinition
{
    /**
     * @param class-string<SyntaxContract>&class-string<BlockSyntaxContract>|class-string<InlineSyntaxContract> $syntax
     * @param class-string<Transfer>                                                                           $transfer
     */
    public function __construct(
        public string $name,
        public string $syntax,
        public string $transfer,
        public string $template,
        public int $priority,
        public bool $block,
        public bool $inline,
    ) {}
}
