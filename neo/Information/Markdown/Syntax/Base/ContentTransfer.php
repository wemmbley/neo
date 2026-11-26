<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Transfer\Transfer;

/**
 * Общий Transfer для обёрток вокруг содержимого (жирный, курсив, цитата…).
 */
class ContentTransfer extends Transfer
{
    # HTML (уже безопасный): выводить через {!! !!}
    #
    public string $content;
}
