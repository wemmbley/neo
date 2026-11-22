<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Factories;

use Closure;
use Neo\Information\Persistence\ORM\Model;

/**
 * Базовая фабрика. Наследник реализует definition() и указывается на модели:
 * `#[UseFactory(ProductFactory::class)]`.
 *
 * @template TModel of Model
 */
abstract class Factory
{
    /** @var class-string<TModel> */
    protected string $model;

    protected int $count = 1;

    /** @var list<array|Closure> */
    protected array $states = [];

    /** @param class-string<TModel> $model */
    public function forModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /** @return array<string, mixed> */
    abstract public function definition(): array;

    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(1, $count);

        return $clone;
    }

    public function state(array|Closure $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    /** @return array<string, mixed> атрибуты без сохранения */
    public function raw(array $overrides = []): array
    {
        $attributes = $this->definition();

        foreach ($this->states as $state) {
            $attributes = [...$attributes, ...($state instanceof Closure ? $state($attributes) : $state)];
        }

        return [...$attributes, ...$overrides];
    }

    /** @return TModel|list<TModel> модель (count = 1) или список, без сохранения */
    public function make(array $overrides = []): Model|array
    {
        return $this->build($overrides, false);
    }

    /** @return TModel|list<TModel> модель (count = 1) или список, сохранённые в БД */
    public function create(array $overrides = []): Model|array
    {
        return $this->build($overrides, true);
    }

    private function build(array $overrides, bool $persist): Model|array
    {
        $models = [];

        for ($i = 0; $i < $this->count; $i++) {
            $model = (new $this->model())->forceFill($this->raw($overrides));
            $persist && $model->save();
            $models[] = $model;
        }

        return $this->count === 1 ? $models[0] : $models;
    }
}
