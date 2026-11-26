<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\AudioSyntax;

use Neo\Transfer\Transfer;

class AudioSyntaxTransfer extends Transfer
{
    public string $src;

    public ?string $title = null;
}
