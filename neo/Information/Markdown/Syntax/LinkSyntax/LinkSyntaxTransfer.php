<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\LinkSyntax;

use Neo\Transfer\Transfer;

class LinkSyntaxTransfer extends Transfer
{
    public string $url;

    # HTML текста ссылки (уже безопасный): {!! !!}
    #
    public string $content;

    public ?string $title = null;

    # Ссылка ведёт на другой сайт (http(s):// или //)
    #
    public bool $external = false;
}
