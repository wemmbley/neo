<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\TableSyntax;

use Neo\Transfer\Transfer;

class TableSyntaxTransfer extends Transfer
{
    # HTML ячеек заголовка (уже безопасный): {!! !!}
    #
    /** @var list<string> */
    public array $head = [];

    # Выравнивание колонок: 'left' | 'center' | 'right' | null
    #
    /** @var list<string|null> */
    public array $align = [];

    # HTML ячеек строк (уже безопасный): {!! !!}
    #
    /** @var list<list<string>> */
    public array $rows = [];
}
