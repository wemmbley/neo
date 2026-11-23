<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

use Neo\Presentation\View\Directives\Contracts\CsrfDirectiveContract;

/** @csrf — скрытое поле с токеном. Токен берётся в рантайме, не зашивается в кэш. */
class CsrfDirective extends BasicDirective
{
    public string $directive = '@csrf';

    public function __construct(private CsrfDirectiveContract $csrf)
    {
    }

    public function render(string $expression): string
    {
        return $this->echo("{$this->self()}->field()");
    }

    /** Рантайм: вызывается из скомпилированных шаблонов. */
    public function field(): string
    {
        $token = htmlspecialchars($this->csrf->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<input type="hidden" name="_token" value="' . $token . '">';
    }
}
