<?php

namespace Neo\Presentation\View\Infrastructure;

use Neo\Network\Http\Contracts\ViewRendererContract;
use Neo\Presentation\View\ViewManager;

/**
 * Мост HTTP -> шаблонизатор: ViewResponse (`response()->view()`, `Route::view()`)
 * рисует шаблон через ViewManager.
 */
class HttpViewRenderer implements ViewRendererContract
{
    public function __construct(private readonly ViewManager $views) {}

    public function render(string $view, array $data = []): string
    {
        return $this->views->render($view, $data);
    }
}
