<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax;

use Neo\Information\Markdown\Syntax\AudioSyntax\AudioSyntax;
use Neo\Information\Markdown\Syntax\BoldSyntax\BoldSyntax;
use Neo\Information\Markdown\Syntax\BulletListSyntax\BulletListSyntax;
use Neo\Information\Markdown\Syntax\CodeBlockSyntax\CodeBlockSyntax;
use Neo\Information\Markdown\Syntax\DividerSyntax\DividerSyntax;
use Neo\Information\Markdown\Syntax\EmbedSyntax\EmbedSyntax;
use Neo\Information\Markdown\Syntax\HeaderFiveSyntax\HeaderFiveSyntax;
use Neo\Information\Markdown\Syntax\HeaderFourSyntax\HeaderFourSyntax;
use Neo\Information\Markdown\Syntax\HeaderOneSyntax\HeaderOneSyntax;
use Neo\Information\Markdown\Syntax\HeaderSixSyntax\HeaderSixSyntax;
use Neo\Information\Markdown\Syntax\HeaderThreeSyntax\HeaderThreeSyntax;
use Neo\Information\Markdown\Syntax\HeaderTwoSyntax\HeaderTwoSyntax;
use Neo\Information\Markdown\Syntax\HtmlSyntax\HtmlSyntax;
use Neo\Information\Markdown\Syntax\ImageSyntax\ImageSyntax;
use Neo\Information\Markdown\Syntax\InlineCodeSyntax\InlineCodeSyntax;
use Neo\Information\Markdown\Syntax\ItalicSyntax\ItalicSyntax;
use Neo\Information\Markdown\Syntax\LinkSyntax\LinkSyntax;
use Neo\Information\Markdown\Syntax\OrderedListSyntax\OrderedListSyntax;
use Neo\Information\Markdown\Syntax\ParagraphSyntax\ParagraphSyntax;
use Neo\Information\Markdown\Syntax\QuoteSyntax\QuoteSyntax;
use Neo\Information\Markdown\Syntax\StrikeSyntax\StrikeSyntax;
use Neo\Information\Markdown\Syntax\TableSyntax\TableSyntax;
use Neo\Information\Markdown\Syntax\VideoFileSyntax\VideoFileSyntax;

/**
 * Встроенные синтаксисы. Регистрируются всегда; их можно заменить через registerSyntax()
 * или убрать через unregisterSyntax().
 */
final class CoreSyntax
{
    /**
     * @return list<class-string<\Neo\Information\Markdown\Contracts\SyntaxContract>>
     */
    public static function all(): array
    {
        return [
            // блочные
            CodeBlockSyntax::class,
            HeaderOneSyntax::class,
            HeaderTwoSyntax::class,
            HeaderThreeSyntax::class,
            HeaderFourSyntax::class,
            HeaderFiveSyntax::class,
            HeaderSixSyntax::class,
            DividerSyntax::class,
            QuoteSyntax::class,
            TableSyntax::class,
            BulletListSyntax::class,
            OrderedListSyntax::class,
            EmbedSyntax::class,
            VideoFileSyntax::class,
            AudioSyntax::class,
            ImageSyntax::class,
            HtmlSyntax::class,
            ParagraphSyntax::class,

            // inline
            InlineCodeSyntax::class,
            LinkSyntax::class,
            BoldSyntax::class,
            StrikeSyntax::class,
            ItalicSyntax::class,
        ];
    }
}
