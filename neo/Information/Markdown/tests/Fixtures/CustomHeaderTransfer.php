<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Tests\Fixtures;

use Neo\Information\Markdown\Syntax\Base\HeaderSyntaxTransfer;

/**
 * Свой Transfer заголовка: наследует стандартный и добавляет поле со значением по умолчанию.
 */
class CustomHeaderTransfer extends HeaderSyntaxTransfer
{
    public string $kicker = 'KICKER';
}
