<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * @include('view', ['a' => 1])
 *
 * Без слотов: подшаблон видит переменные родителя + переданные данные.
 * Если перед ним были @slot — это компонент: изолированный scope,
 * внутри только $props (переданный массив) и $slots.
 */
class IncludeDirective extends BasicDirective
{
    public string $directive = '@include';

    public function render(string $expression): string
    {
        return $this->echo("\$this->includeView({$expression}, scope: get_defined_vars())");
    }
}
