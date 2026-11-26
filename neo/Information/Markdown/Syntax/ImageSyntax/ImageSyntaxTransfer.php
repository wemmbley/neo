<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\ImageSyntax;

use Neo\Transfer\Transfer;

class ImageSyntaxTransfer extends Transfer
{
    public string $src;

    # Текст alt без разметки
    #
    public string $alt = '';

    public ?string $title = null;

    # Ссылка, в которую обёрнута картинка: [![alt](src)](href)
    #
    public ?string $link = null;

    # true — картинка занимает строку целиком, false — внутри абзаца
    #
    public bool $block = false;
}
