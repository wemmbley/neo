<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Transfer\Transfer;

class HeaderSyntaxTransfer extends Transfer
{
    public int $level;

    # HTML заголовка (уже безопасный): выводить через {!! !!}
    #
    public string $content;

    # Заголовок без разметки: выводить через {{ }}
    #
    public string $text;

    # Якорь, уникальный в пределах документа
    #
    public string $id;
}
