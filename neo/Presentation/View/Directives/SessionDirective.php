<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

use Neo\Presentation\View\Directives\Contracts\SessionDirectiveContract;

/**
 * @session('flash') ... {{ $value }} ... @endsession
 * Блок показывается, если ключ есть в сессии; значение лежит в $value.
 */
class SessionDirective extends BasicDirective
{
    public string $directive = '@session';
    public ?string $directiveEnd = '@endsession';

    public function __construct(private SessionDirectiveContract $session)
    {
    }

    public function render(string $expression): string
    {
        return $this->php(
            "\$__key = {$expression}; if ({$this->self()}->has(\$__key)): "
            . "\$value = {$this->self()}->get(\$__key);"
        );
    }

    public function close(): ?string
    {
        return $this->php('unset($value); endif;');
    }

    /** Рантайм: вызывается из скомпилированных шаблонов. */
    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    /** Рантайм: вызывается из скомпилированных шаблонов. */
    public function get(string $key): mixed
    {
        return $this->session->get($key);
    }
}
