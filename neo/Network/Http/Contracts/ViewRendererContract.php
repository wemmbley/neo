<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

/**
 * Мост между HTTP и шаблонизатором: ViewResponse просит отрисовать шаблон.
 * Реализацию привязывает модуль Presentation (View::).
 */
interface ViewRendererContract
{
    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string;
}
