<?php

declare(strict_types=1);

namespace Neo\Autoload;

class AliasLoader
{
    /** @var array<string, class-string> */
    private array $aliases = [];

    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    public function alias(string $alias, string $class): static
    {
        $this->aliases[$alias] = $class;

        return $this;
    }

    /**
     * Короткое имя берётся из последнего сегмента: Neo\Storage\Storage => Storage.
     *
     * @param list<class-string> $facades
     */
    public function facades(array $facades): static
    {
        foreach ($facades as $facade) {
            $this->alias(substr(strrchr('\\' . $facade, '\\'), 1), $facade);
        }

        return $this;
    }

    public function load(string $alias): void
    {
        if (isset($this->aliases[$alias])) {
            class_alias($this->aliases[$alias], $alias);
        }
    }
}