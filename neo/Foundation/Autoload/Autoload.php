<?php

declare(strict_types=1);

namespace Neo\Foundation\Autoload;

/**
 * PSR-4 автозагрузчик на spl_autoload_register без Composer.
 *
 *   new Autoload(
 *       classes: ['/path/src' => 'Neo', '/path/app' => 'App'],
 *       files:   ['/path/src/Kernel/helpers.php'],
 *   ))->load();
 */
class Autoload
{
    /** @var array<string, string> путь => пространство имён */
    private array $classes;

    /** @var list<string> */
    private array $files;

    /**
     * @param array<string, string> $classes Путь => пространство имён.
     * @param list<string>          $files   Файлы, подключаемые сразу (хелперы).
     */
    public function __construct(
        array $classes,
        array $files = []
    ) {
        $this->classes = $classes;
        $this->files = $files;
    }

    public function load(): void
    {
        spl_autoload_register([$this, 'autoload']);

        $this->loadFiles();
    }

    private function loadFiles(): void
    {
        foreach ($this->files as $file) {
            if (!is_file($file)) {
                throw new \RuntimeException("Файл автозагрузки не найден: {$file}");
            }

            require_once $file;
        }
    }

    public function autoload(string $class): void
    {
        foreach ($this->classes as $path => $namespace) {
            $prefix = trim($namespace, '\\') . '\\';

            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));

            $file = rtrim($path, '/\\')
                . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
                . '.php';

            if (is_file($file)) {
                require_once $file;

                return;
            }
        }
    }
}
