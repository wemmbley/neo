<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Transfer;

use Neo\Transfer\Transfer;

class HtmlModeTransfer extends Transfer
{
    # Сырой HTML из markdown экранируется как текст.
    #
    public bool $escape = true;

    # Блокировка небезопасных URL (javascript:, data:, vbscript: ...).
    #
    public bool $sanitize = true;
}
