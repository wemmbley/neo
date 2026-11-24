<?php

declare(strict_types=1);

namespace Neo\Presentation\View;

use Closure;
use Neo\Presentation\View\Directives\BasicDirective;
use Neo\Presentation\View\Transfers\ConfigTransfer;

/**
 * Точка входа движка шаблонов.
 *
 * $view = new View($config);
 * echo $view->render('pages.home', ['title' => 'Hello']);
 * echo $view->fragment('pages.home', 'sidebar', $data);
 *
 * Конфиг валидируется сам: ConfigTransfer → CacheTransfer / CompilerTransfer.
 */
class ViewManager
{
    private ConfigTransfer $configDto;
    private Compiler $compiler;
    private Renderer $renderer;

    /**
     * Общие данные для всех шаблонов, вычисляемые на КАЖДЫЙ рендер (например, $errors из сессии
     * текущего запроса). ViewManager общий для процесса, поэтому данные запроса сюда не пишутся,
     * а берутся функцией: это безопасно при одновременной обработке запросов (Swoole).
     *
     * @var Closure(): array<string, mixed>|null
     */
    private ?Closure $sharedResolver = null;

    public function __construct(
        private array $config = [],
    ) {
        # Преображаем конфиг из массива в объект для удобства.
        # Здесь же происходит валидация (бросит ValidationException).
        $this->setConfig();

        $this->compiler = new Compiler($this->configDto->compiler);
        $this->renderer = new Renderer($this->configDto, $this->compiler);
    }

    /**
     * Отрендерить шаблон: «pages.home» или «namespace::pages.home».
     */
    public function render(string $view, array $data = []): string
    {
        return $this->renderer->render($view, $this->withShared($data));
    }

    /**
     * Отрендерить только @fragment из шаблона.
     */
    public function fragment(string $view, string $fragment, array $data = []): string
    {
        return $this->renderer->renderFragment($view, $fragment, $this->withShared($data));
    }

    /**
     * Подключает источник общих данных: `View::shareUsing(fn () => ['errors' => ...])`.
     * Данные, переданные в render() напрямую, имеют приоритет.
     *
     * @param Closure(): array<string, mixed> $resolver
     */
    public function shareUsing(Closure $resolver): static
    {
        $this->sharedResolver = $resolver;

        return $this;
    }

    /** @param array<string, mixed> $data */
    private function withShared(array $data): array
    {
        return $this->sharedResolver === null ? $data : [...($this->sharedResolver)(), ...$data];
    }

    public function exists(string $view): bool
    {
        return $this->renderer->exists($view);
    }

    /**
     * Зарегистрировать директиву в рантайме (@auth, @role ...).
     * Если кэш включён — после смены набора директив вызовите flush().
     */
    public function directive(BasicDirective $directive): static
    {
        $this->compiler->add($directive);

        return $this;
    }

    /**
     * Сбросить кэш шаблонов.
     */
    public function flush(): void
    {
        $this->renderer->flush();
    }

    private function setConfig(): void
    {
        $data = $this->config;
        $data['cache'] ??= [];
        $data['compiler'] ??= [];

        $this->configDto = new ConfigTransfer($data);
    }
}
