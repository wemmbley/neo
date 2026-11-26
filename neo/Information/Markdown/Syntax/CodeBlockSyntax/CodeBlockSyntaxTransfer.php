<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\CodeBlockSyntax;

use Neo\Transfer\Transfer;

class CodeBlockSyntaxTransfer extends Transfer
{
    # Язык из ```lang (только безопасные символы) или null
    #
    public ?string $lang = null;

    # Сырой код: выводить через {{ }}
    #
    public string $code;
}
