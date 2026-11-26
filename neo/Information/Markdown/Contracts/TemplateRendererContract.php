<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Contracts;

/**
 * Единственная точка, где пакет касается blade-движка проекта.
 */
interface TemplateRendererContract
{
    /**
     * Создаёт View для файла шаблона по абсолютному пути.
     *
     * @param array<string, mixed> $data
     */
    public function make(string $path, array $data = []): object;

    /**
     * Рендерит шаблон в строку (с обрезанными краевыми пробелами).
     *
     * @param array<string, mixed> $data
     */
    public function render(string $path, array $data = []): string;
}
