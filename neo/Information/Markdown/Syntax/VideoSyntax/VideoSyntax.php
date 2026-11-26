<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\VideoSyntax;

use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Syntax\Base\BasicDirectiveSyntax;

/**
 * ::video{title="Заголовок" thumbnail="https://…/preview.jpg" url="https://…/video"}
 *
 * Не входит во встроенные: подключается через config['syntax'] или registerSyntax().
 */
class VideoSyntax extends BasicDirectiveSyntax
{
    public function __construct(
        public VideoSyntaxTransfer $config,
    ) {}

    public function markdown(): string
    {
        return sprintf(
            '[![%s](%s)](%s)',
            addcslashes($this->config->title, '\\[]'),
            $this->config->thumbnail,
            $this->config->url,
        );
    }

    protected static function accepts(array $attributes, Context $context): bool
    {
        foreach (['url', 'thumbnail'] as $key) {
            if (isset($attributes[$key]) && !$context->safeUrl($attributes[$key])) {
                return false;
            }
        }

        return true;
    }
}
