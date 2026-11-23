<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @error('email') ... {{ $message }} ... @enderror
 * Ошибки берутся из переменной $errors (['field' => 'text'|['text', ...]]).
 */
class ErrorDirective extends BasicDirective
{
    public string $directive = '@error';
    public ?string $directiveEnd = '@enderror';

    public function render(string $expression): string
    {
        return $this->php("if (\$message = \$this->error(\$errors ?? [], {$expression})):");
    }

    public function close(): ?string
    {
        return $this->php('unset($message); endif;');
    }
}
