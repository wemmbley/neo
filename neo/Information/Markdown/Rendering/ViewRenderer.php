<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Rendering;

use Neo\Information\Markdown\Contracts\TemplateRendererContract;
use Neo\View\View;

/**
 * Рендер через View проекта. Если у вашего View другой способ
 * открыть файл по пути — меняйте только метод make().
 */
final class ViewRenderer implements TemplateRendererContract
{
    public function make(string $path, array $data = []): object
    {
        return View::file($path, $data);
    }

    public function render(string $path, array $data = []): string
    {
        return trim((string) $this->make($path, $data)->render());
    }
}
