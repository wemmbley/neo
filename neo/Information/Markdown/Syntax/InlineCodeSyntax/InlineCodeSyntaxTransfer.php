<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\InlineCodeSyntax;

use Neo\Transfer\Transfer;

class InlineCodeSyntaxTransfer extends Transfer
{
    # Сырой код: выводить через {{ }}
    #
    public string $code;
}
