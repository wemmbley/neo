<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\BulletListSyntax;

use Neo\Transfer\Transfer;

class BulletListSyntaxTransfer extends Transfer
{
    # HTML каждого пункта (уже безопасный): выводить через {!! !!}
    #
    /** @var list<string> */
    public array $items = [];
}
