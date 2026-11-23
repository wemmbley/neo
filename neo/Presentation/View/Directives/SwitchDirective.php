<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @switch ($x) @case(1) ... @break @default ... @endswitch */
class SwitchDirective extends BasicDirective
{
    public string $directive = '@switch';
    public ?string $directiveEnd = '@endswitch';
    public array $branches = ['@case', '@default', '@break'];

    # Между switch и первым case в PHP нельзя выводить даже пробел.
    public bool $trimAfter = true;

    public function render(string $expression): string
    {
        return $this->php("switch ({$expression}):");
    }

    public function branch(string $name, string $expression): string
    {
        return match ($name) {
            '@case'    => $this->php("case ({$expression}):"),
            '@default' => $this->php('default:'),
            default   => $this->php('break;'),
        };
    }

    public function close(): ?string
    {
        return $this->php('endswitch;');
    }
}
