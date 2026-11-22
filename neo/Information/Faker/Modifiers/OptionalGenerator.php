<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Modifiers;

use Neo\Information\Faker\Faker;

/**
 * Proxy returned by $faker->optional(): with the given probability returns
 * the real value, otherwise the default (null).
 */
final class OptionalGenerator
{
    private readonly float $weight;

    public function __construct(
        private readonly Faker $faker,
        float $weight = 0.5,
        private readonly mixed $default = null,
    ) {
        $this->weight = max(0.0, min(1.0, $weight));
    }

    public function __get(string $name): mixed
    {
        return $this->__call($name, []);
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->faker->rng()->chance($this->weight)
            ? $this->faker->__call($method, $arguments)
            : $this->default;
    }
}
