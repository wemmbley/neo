<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HtmlSyntax;

use Neo\Transfer\Transfer;

class HtmlSyntaxTransfer extends Transfer
{
    # Сырой HTML из документа (доверенный режим)
    #
    public string $html;
}
