<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\OrderedListSyntax;

use Neo\Transfer\Transfer;

class OrderedListSyntaxTransfer extends Transfer
{
    # HTML каждого пункта (уже безопасный): выводить через {!! !!}
    #
    /** @var list<string> */
    public array $items = [];

    # Номер первого пункта
    #
    public int $start = 1;
}
