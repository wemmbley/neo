<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @verbatim ... @endverbatim — выводит тело как есть: {{ }} и @директивы не трогаются
 * (удобно для Vue/Alpine шаблонов).
 */
class VerbatimDirective extends BasicDirective
{
    public string $directive = '@verbatim';
    public ?string $directiveEnd = '@endverbatim';
    public bool $rawBody = true;

    public function render(string $expression): string
    {
        return '';
    }

    public function body(string $raw): string
    {
        // Чтобы «<?php» из тела не выполнился при include скомпилированного файла.
        return str_replace('<?', "<?= '<?' ?>", $raw);
    }
}
