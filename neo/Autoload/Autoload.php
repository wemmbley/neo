<?php

declare(strict_types=1);

namespace Neo\Autoload;

class Autoload
{
    private array $classes;
    private array $files;

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
            if (is_file($file)) {
                require_once $file;
            }
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
            }

            return;
        }
    }
}