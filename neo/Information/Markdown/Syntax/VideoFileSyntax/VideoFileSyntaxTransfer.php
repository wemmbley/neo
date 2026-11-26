<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\VideoFileSyntax;

use Neo\Transfer\Transfer;

class VideoFileSyntaxTransfer extends Transfer
{
    public string $src;

    public ?string $title = null;
}
