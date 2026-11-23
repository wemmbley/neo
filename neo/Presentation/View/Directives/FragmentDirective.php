<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @fragment('name') ... @endfragment
 * Кусок можно отрендерить отдельно: View::fragment('view', 'name', $data).
 */
class FragmentDirective extends BasicDirective
{
    public string $directive = '@fragment';
    public ?string $directiveEnd = '@endfragment';

    public function render(string $expression): string
    {
        return $this->php("\$this->fragmentStart({$expression});");
    }

    public function close(): ?string
    {
        return $this->php('$this->fragmentEnd();');
    }
}
