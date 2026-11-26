<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\EmbedSyntax;

use Neo\Transfer\Transfer;

class EmbedSyntaxTransfer extends Transfer
{
    # youtube | vimeo
    #
    public string $provider;

    # ID видео у провайдера
    #
    public string $id;

    # Исходная ссылка
    #
    public string $url;

    # Адрес для iframe
    #
    public string $src;

    # Превью (есть у YouTube)
    #
    public ?string $thumbnail = null;

    public ?string $title = null;
}
