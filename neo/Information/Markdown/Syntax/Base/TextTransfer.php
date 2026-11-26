<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Transfer\Transfer;

class TextTransfer extends Transfer
{
    # HTML (уже безопасный): выводить через {!! !!}
    #
    public string $content;

    # Тот же текст без разметки: выводить через {{ }}
    #
    public string $text;
}
