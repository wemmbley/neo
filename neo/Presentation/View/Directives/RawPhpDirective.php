<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @php($a = 1)  — строчная форма.
 * @php ... @endphp — блочная, тело не разбирается компилятором.
 */
class RawPhpDirective extends BasicDirective
{
    public string $directive = '@php';
    public ?string $directiveEnd = '@endphp';
    public bool $rawBody = true;

    public function render(string $expression): string
    {
        return $this->php(rtrim($expression, "; \t\n\r") . ';');
    }

    public function body(string $raw): string
    {
        return $this->php($raw);
    }
}
