<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @stack('scripts') — место вывода накопленных @push.
 * Можно ставить хоть в <head>: подстановка происходит после рендера всей страницы.
 */
class StackDirective extends BasicDirective
{
    public string $directive = '@stack';

    public function render(string $expression): string
    {
        return $this->echo("\$this->stack({$expression})");
    }
}
