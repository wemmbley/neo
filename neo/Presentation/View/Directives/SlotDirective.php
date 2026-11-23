<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @slot('header') ... @endslot — собирает слот для ближайшего @include.
 * В компоненте он доступен как $slots->header (уже готовый HTML, выводить через сырой вывод).
 */
class SlotDirective extends BasicDirective
{
    public string $directive = '@slot';
    public ?string $directiveEnd = '@endslot';

    public function render(string $expression): string
    {
        return $this->php("\$this->slotStart({$expression});");
    }

    public function close(): ?string
    {
        return $this->php('$this->slotEnd();');
    }
}
