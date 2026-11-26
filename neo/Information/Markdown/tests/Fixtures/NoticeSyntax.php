<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Tests\Fixtures;

use Neo\Information\Markdown\Syntax\Base\BasicDirectiveSyntax;
use Neo\Transfer\Transfer;

class NoticeSyntaxTransfer extends Transfer
{
    public string $type = 'info';

    public string $text;
}

/**
 * Пример расширения: директива ::notice{type="warning" text="..."}
 */
class NoticeSyntax extends BasicDirectiveSyntax
{
    public function __construct(public NoticeSyntaxTransfer $config) {}
}
