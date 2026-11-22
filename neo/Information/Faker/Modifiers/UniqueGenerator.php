<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Modifiers;

use Neo\Information\Faker\Exceptions\UniqueLimitException;
use Neo\Information\Faker\Faker;

/**
 * Proxy returned by $faker->unique(): never returns the same value twice
 * for the same formatter until it is reset with unique(true).
 */
final class UniqueGenerator
{
    /** @var array<string, array<string, true>> */
    private array $seen = [];

    public function __construct(
        private readonly Faker $faker,
        private readonly int $maxRetries = 10000,
    ) {}

    public function __get(string $name): mixed
    {
        return $this->__call($name, []);
    }

    public function __call(string $method, array $arguments): mixed
    {
        $this->seen[$method] ??= [];
        $attempts = 0;

        do {
            if ($attempts++ >= $this->maxRetries) {
                throw new UniqueLimitException(
                    "Unable to generate a unique value for [{$method}] in {$this->maxRetries} attempts.",
                );
            }

            $value = $this->faker->__call($method, $arguments);
            $key = serialize($value);
        } while (isset($this->seen[$method][$key]));

        $this->seen[$method][$key] = true;

        return $value;
    }
}
