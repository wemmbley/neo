<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\VideoSyntax;

use Neo\Transfer\Transfer;
use Neo\Validator\Rule;

class VideoSyntaxTransfer extends Transfer
{
    # Заголовок видео
    #
    public string $title = 'Title';

    # Картинка-превью видео
    #
    public string $thumbnail;

    # Ссылка на видео
    #
    public string $url;

    public function validateTitle(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->string()
            ->max(255);
    }

    public function validateThumbnail(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->string();
    }

    public function validateUrl(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->string();
    }
}
