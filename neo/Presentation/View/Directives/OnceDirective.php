<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @once ... @endonce        — блок один раз за рендер (по месту в файле).
 * @once('vue') ... @endonce — один раз по ключу (между разными шаблонами).
 *
 * «pushonce» = @once + @push.
 */
class OnceDirective extends BasicDirective
{
    public string $directive = '@once';
    public ?string $directiveEnd = '@endonce';

    public function render(string $expression): string
    {
        // Без ключа — «по месту в файле»: рендерер сам добавит путь шаблона.
        $call = $expression !== ''
            ? "\$this->once({$expression})"
            : "\$this->once('#" . (++$this->counter) . "', true)";

        return $this->php("if ({$call}):");
    }

    public function close(): ?string
    {
        return $this->php('endif;');
    }
}
