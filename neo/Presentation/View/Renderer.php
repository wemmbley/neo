<?php

declare(strict_types=1);

namespace Neo\Presentation\View;

use Neo\Information\Cache\Cache;
use Neo\Presentation\View\Directives\BasicDirective;
use Neo\Presentation\View\Exceptions\ViewException;
use Neo\Presentation\View\Transfers\ConfigTransfer;
use Throwable;

/**
 * Находит шаблон, компилирует (с кэшем), выполняет и хранит рантайм-состояние
 * одного рендера: стеки @push, слоты, фрагменты, циклы, @once.
 *
 * Скомпилированный шаблон исполняется внутри этого объекта, поэтому в нём
 * доступен $this->e(), $this->includeView() и остальные публичные хелперы ниже.
 */
class Renderer
{
    /** @var list<Loop> */
    private array $loops = [];

    /** @var array<string, list<string>> имя стека => накопленные куски */
    private array $stacks = [];

    /** @var list<string> имена открытых @push */
    private array $pushOpen = [];

    /** @var array<string, true> */
    private array $onceKeys = [];

    /** @var array<string, string> слоты для ближайшего @include */
    private array $pendingSlots = [];

    /** @var list<array{0: string, 1: array}> открытые @slot: имя + отложенные слоты */
    private array $slotOpen = [];

    /** @var array<string, string> */
    private array $fragments = [];

    /** @var list<string> */
    private array $fragmentOpen = [];

    /** @var list<array{0: string, 1: int}|null> открытые @cache (null — не кэшируем) */
    private array $cacheOpen = [];

    /** @var array<string, string> */
    private array $located = [];

    /** @var array<string, array{0: int, 1: string}> источник => [mtime, путь к скомпилированному файлу] при выключенном кэше */
    private array $compiledInMemory = [];

    /** @var list<string> стек путей выполняемых шаблонов (для @once «по месту»). */
    private array $sources = [];

    private int $depth = 0;

    public function __construct(
        private ConfigTransfer $config,
        private Compiler $compiler,
    ) {
    }

    # ---------------------------------------------------------------- Публичное API

    public function render(
        string $view,
        array $data = [],
    ): string {
        return $this->depth === 0
            ? $this->renderTop($view, $data, true)
            : $this->evaluate($view, $data);
    }

    public function renderComponent(
        string $view,
        array $props = [],
        array $slots = []
    ): string {
        return $this->render($view, [
            'props' => (object) $props,
            'slots' => (object) $slots,
        ]);
    }

    /**
     * Отрендерить только @fragment('name') из шаблона.
     */
    public function renderFragment(string $view, string $fragment, array $data = []): string
    {
        $this->renderTop($view, $data, false, $captured);

        return $captured[$fragment]
            ?? throw new ViewException("Фрагмент «{$fragment}» не найден в шаблоне «{$view}».");
    }

    public function exists(string $view): bool
    {
        try {
            $this->locate($view);

            return true;
        } catch (ViewException) {
            return false;
        }
    }

    /**
     * Удалить скомпилированные PHP-файлы шаблонов.
     * Содержимое Cache (вывод шаблонов и @cache) сбрасывается средствами самого Cache.
     */
    public function flush(): void
    {
        $root = rtrim($this->config->cache->path, '/\\');
        foreach (glob("{$root}/compiled/*") ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    # ---------------------------------------------------------------- Хелперы для скомпилированных шаблонов

    /** Экранирование для {{ }}. */
    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            throw new ViewException('Массив нельзя вывести через {{ }}: используйте @json или implode().');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, $this->config->encoding);
    }

    /** Безопасный JSON для HTML (подходит и для атрибутов, и для <script>). */
    public function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /** Живой экземпляр директивы (для csrf, session и т.п.). */
    public function directive(string $class): BasicDirective
    {
        return $this->compiler->directive($class);
    }

    /** @error: первое сообщение об ошибке поля или null. */
    public function error(array|object $errors, string $field): ?string
    {
        $value = is_array($errors) ? ($errors[$field] ?? null) : ($errors->{$field} ?? null);

        if (is_array($value)) {
            $value = reset($value);
        }

        return ($value === null || $value === false || $value === '') ? null : (string) $value;
    }

    /**
     * @include. Если перед ним были @slot — рендерит как компонент.
     */
    public function includeView(string $view, array $data = [], array $scope = []): string
    {
        if ($this->pendingSlots) {
            $slots = $this->pendingSlots;
            $this->pendingSlots = [];

            return $this->renderComponent($view, $data, $slots);
        }

        // Служебные переменные скомпилированного шаблона (__path, __items1 ...) наружу не отдаём.
        $scope = array_filter($scope, static fn($key) => !str_starts_with((string) $key, '__'), ARRAY_FILTER_USE_KEY);

        return $this->render($view, $data + $scope);
    }

    # @foreach / $loop

    public function loopPush(iterable $items): void
    {
        $parent = $this->loops ? end($this->loops) : null;
        $this->loops[] = new Loop($items, count($this->loops) + 1, $parent);
    }

    public function loopTick(): Loop
    {
        return end($this->loops)->advance();
    }

    public function loopPop(): void
    {
        array_pop($this->loops);
    }

    public function loopCurrent(): ?Loop
    {
        return $this->loops ? end($this->loops) : null;
    }

    # @push / @stack

    public function pushStart(string $name): void
    {
        $this->pushOpen[] = $name;
        ob_start();
    }

    public function pushEnd(): void
    {
        $name = array_pop($this->pushOpen);
        $this->stacks[$name][] = (string) ob_get_clean();
    }

    /** Возвращает метку; настоящий вывод подставляется после рендера всей страницы. */
    public function stack(string $name): string
    {
        return "\0neo-stack:{$name}\0";
    }

    # @slot

    public function slotStart(string $name): void
    {
        // Слоты, собранные ранее, прячем: @include внутри этого слота не должен их забрать.
        $this->slotOpen[] = [$name, $this->pendingSlots];
        $this->pendingSlots = [];
        ob_start();
    }

    public function slotEnd(): void
    {
        [$name, $saved] = array_pop($this->slotOpen);
        $saved[$name] = (string) ob_get_clean();
        $this->pendingSlots = $saved;
    }

    # @once

    public function once(string $key, bool $local = false): bool
    {
        if ($local) {
            $key = end($this->sources) . $key;
        }

        if (isset($this->onceKeys[$key])) {
            return false;
        }

        return $this->onceKeys[$key] = true;
    }

    # @fragment

    public function fragmentStart(string $name): void
    {
        $this->fragmentOpen[] = $name;
        ob_start();
    }

    public function fragmentEnd(): void
    {
        $name = array_pop($this->fragmentOpen);
        $html = (string) ob_get_clean();
        $this->fragments[$name] = $html;
        echo $html;
    }

    # @cache

    /**
     * true — кусок найден в Cache и уже выведен (тело пропускается).
     * false — тело надо выполнить (и, если кэш активен, оно будет сохранено в cacheEnd).
     */
    public function cacheBegin(string $key, ?int $ttl = null): bool
    {
        $cache = $this->config->cache;

        if (!$cache->enabled || $cache->strategy !== 'directive') {
            $this->cacheOpen[] = null;

            return false;
        }

        $key = 'view.fragment.' . sha1($key);

        if (Cache::has($key)) {
            $this->cacheOpen[] = null;
            echo Cache::get($key);

            return true;
        }

        $this->cacheOpen[] = [$key, $ttl];
        ob_start();

        return false;
    }

    public function cacheEnd(): void
    {
        $frame = array_pop($this->cacheOpen);
        if ($frame === null) {
            return;
        }

        $html = (string) ob_get_clean();
        Cache::put($frame[0], $html, $this->ttl($frame[1]));
        echo $html;
    }

    # ---------------------------------------------------------------- Внутренняя кухня

    /**
     * Рендер верхнего уровня: сбрасывает состояние, подставляет @stack, умеет кэш вывода.
     */
    private function renderTop(string $view, array $data, bool $useOutputCache, ?array &$captured = null): string
    {
        $this->loops = $this->pushOpen = $this->slotOpen = $this->fragmentOpen = $this->cacheOpen = [];
        $this->stacks = $this->onceKeys = $this->pendingSlots = $this->fragments = [];

        $cache = $this->config->cache;
        $cacheKey = null;

        if ($useOutputCache && $cache->enabled && $cache->strategy === 'full') {
            $cacheKey = $this->outputKey($view, $data);
            if ($cacheKey !== null && Cache::has($cacheKey)) {
                return (string) Cache::get($cacheKey);
            }
        }

        $html = preg_replace_callback(
            '/\x00neo-stack:([^\x00]*)\x00/',
            fn(array $m) => implode('', $this->stacks[$m[1]] ?? []),
            $this->evaluate($view, $data)
        );

        $captured = $this->fragments;

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $html, $this->ttl());
        }

        return $html;
    }

    private function evaluate(string $view, array $data): string
    {
        $source = $this->locate($view);
        [$compiled, $temporary] = $this->compileFile($source);

        $level = ob_get_level();
        ob_start();
        $this->depth++;
        $this->sources[] = $source;

        try {
            $this->run($compiled, $data);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            if ($e instanceof ViewException) {
                throw $e;
            }

            $message = "Ошибка в шаблоне «{$view}» ({$source}): " . $e->getMessage();
            if ($this->config->debug) {
                $message .= " [скомпилированный файл: {$compiled}, строка {$e->getLine()}]";
            }

            throw new ViewException($message, 0, $e);
        } finally {
            array_pop($this->sources);
            $this->depth--;
            if ($temporary) {
                @unlink($compiled);
            }
        }
    }

    /** Изолированное выполнение скомпилированного файла. */
    private function run(string $__path, array $__data): void
    {
        extract($__data, EXTR_SKIP);

        include $__path;
    }

    /**
     * «mynamespace::components.card» или «pages.home» -> путь к файлу.
     * Точки превращаются в «/», так что выйти за пределы папки через «..» нельзя.
     */
    private function locate(string $view): string
    {
        if (isset($this->located[$view])) {
            return $this->located[$view];
        }

        if (!preg_match('~^(?:[A-Za-z][\w-]*::)?[\w\-./]+$~', $view)) {
            throw new ViewException("Недопустимое имя шаблона «{$view}».");
        }

        [$namespace, $name] = str_contains($view, '::') ? explode('::', $view, 2) : [null, $view];

        $root = $namespace === null
            ? $this->config->basicPath
            : ($this->config->namespaces[$namespace]
                ?? throw new ViewException("Пространство имён «{$namespace}» не зарегистрировано."));

        $base = rtrim($root, '/\\') . '/' . str_replace('.', '/', $name);

        foreach ($this->config->extensions as $extension) {
            if (is_file($base . $extension)) {
                return $this->located[$view] = $base . $extension;
            }
        }

        throw new ViewException(
            "Шаблон «{$view}» не найден: {$base}{" . implode(',', $this->config->extensions) . '}'
        );
    }

    /**
     * Возвращает [путь к PHP-файлу, временный ли он].
     */
    private function compileFile(string $source): array
    {
        $cache = $this->config->cache;

        if (!$cache->enabled) {
            # Кэш выключен: шаблон всё равно компилируется один раз на процесс (и заново при изменении файла),
            # а не на каждый запрос с записью временного файла и пересборкой opcache.
            $mtime = (int) @filemtime($source);
            $known = $this->compiledInMemory[$source] ?? null;

            if ($known !== null && $known[0] === $mtime && is_file($known[1])) {
                return [$known[1], false];
            }

            $path = sys_get_temp_dir() . '/neo-view-' . sha1($source . '|' . $mtime . '|' . getmypid()) . '.php';
            $this->writeAtomic($path, $this->compiler->compile($this->read($source)));

            if ($known !== null && $known[1] !== $path) {
                @unlink($known[1]);
            }

            $this->compiledInMemory[$source] = [$mtime, $path];

            return [$path, false];
        }

        $path = $this->cacheDir('compiled') . '/' . sha1($source) . '.php';

        $fresh = is_file($path)
            && filemtime($path) >= filemtime($source)
            && ($cache->lifetime === 0 || time() - filemtime($path) < $cache->lifetime);

        if (!$fresh) {
            $this->writeAtomic($path, $this->compiler->compile($this->read($source)));
        }

        return [$path, false];
    }

    private function read(string $file): string
    {
        $content = @file_get_contents($file);

        return $content === false
            ? throw new ViewException("Не удалось прочитать шаблон {$file}.")
            : $content;
    }

    private function outputKey(string $view, array $data): ?string
    {
        try {
            return 'view.output.' . sha1($view . '|' . serialize($data));
        } catch (Throwable) {
            return null; // в данных есть замыкание или ресурс — такой вывод не кэшируем
        }
    }

    private function cacheDir(string $sub): string
    {
        $dir = rtrim($this->config->cache->path, '/\\') . '/' . $sub;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ViewException("Не удалось создать папку кэша {$dir}.");
        }

        return $dir;
    }

    /** TTL для Cache: null/0 в lifetime — бессрочно. */
    private function ttl(?int $ttl = null): ?int
    {
        $ttl ??= $this->config->cache->lifetime;

        return $ttl > 0 ? $ttl : null;
    }

    /** Запись через временный файл + rename: читатели не увидят недописанный кэш. */
    private function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new ViewException("Не удалось записать файл {$path}.");
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
