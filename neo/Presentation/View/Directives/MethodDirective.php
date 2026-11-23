<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @method('PUT') — скрытое поле _method. */
class MethodDirective extends BasicDirective
{
    public string $directive = '@method';

    public function render(string $expression): string
    {
        return '<input type="hidden" name="_method" value="'
            . $this->echo("\$this->e(strtoupper({$expression}))") . '">';
    }
}
