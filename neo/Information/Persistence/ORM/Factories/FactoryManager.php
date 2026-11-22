<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Factories;

use InvalidArgumentException;
use Neo\Information\Persistence\ORM\Metadata\ModelMetadata;
use Neo\Information\Persistence\ORM\Model;

/**
 * Находит фабрику модели: сначала #[UseFactory], затем по соглашению
 * `{FactoryNamespace}\{Model}Factory`. Модель о фабриках ничего не знает.
 *
 * `Factory::make(Product::class)->count(3)->create()`
 */
class FactoryManager
{
    /** @var array<class-string<Model>, class-string<Factory>> */
    private array $registry = [];

    public function __construct(private readonly string $namespace = 'Database\\Factories\\') {}

    /** Ручная привязка (для моделей из пакетов). */
    public function register(string $model, string $factory): void
    {
        $this->registry[$model] = $factory;
    }

    /** @template T of Model @param class-string<T> $model @return Factory<T> */
    public function make(string $model): Factory
    {
        $class = $this->registry[$model]
            ?? ModelMetadata::for($model)->factory
            ?? $this->namespace . substr($model, (int) strrpos($model, '\\') + 1) . 'Factory';

        if (!class_exists($class) || !is_subclass_of($class, Factory::class)) {
            throw new InvalidArgumentException("Фабрика для {$model} не найдена (искал {$class}).");
        }

        return (new $class())->forModel($model);
    }
}
