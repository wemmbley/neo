<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Seeding;

/**
 * Базовый сидер. `call()` запускает вложенные сидеры через контейнер.
 */
abstract class Seeder
{
    abstract public function run(): void;

    /** @param class-string<Seeder>|list<class-string<Seeder>> $classes */
    public function call(string|array $classes): void
    {
        foreach ((array) $classes as $class) {
            /** @var Seeder $seeder */
            $seeder = function_exists('app') ? app($class) : new $class();
            $seeder->run();
        }
    }
}
