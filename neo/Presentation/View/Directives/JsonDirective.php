<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @json($data): безопасный JSON для вставки в HTML/атрибуты/<script>. */
class JsonDirective extends BasicDirective
{
    public string $directive = '@json';

    public function render(string $expression): string
    {
        return $this->echo("\$this->json({$expression})");
    }
}
