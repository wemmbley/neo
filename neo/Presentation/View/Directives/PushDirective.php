<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @push('scripts') ... @endpush — отложенный вывод в @stack('scripts'). */
class PushDirective extends BasicDirective
{
    public string $directive = '@push';
    public ?string $directiveEnd = '@endpush';

    public function render(string $expression): string
    {
        return $this->php("\$this->pushStart({$expression});");
    }

    public function close(): ?string
    {
        return $this->php('$this->pushEnd();');
    }
}
