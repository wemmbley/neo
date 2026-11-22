<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Modifiers;

use Closure;
use Neo\Information\Faker\Exceptions\FakerException;
use Neo\Information\Faker\Faker;

/**
 * Proxy returned by $faker->valid(fn): retries until the validator accepts the value.
 */
final class ValidGenerator
{
    private readonly Closure $validator;

    public function __construct(
        private readonly Faker $faker,
        ?Closure $validator = null,
        private readonly int $maxRetries = 10000,
    ) {
        $this->validator = $validator ?? static fn (): bool => true;
    }

    public function __get(string $name): mixed
    {
        return $this->__call($name, []);
    }

    public function __call(string $method, array $arguments): mixed
    {
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            $value = $this->faker->__call($method, $arguments);

            if (($this->validator)($value)) {
                return $value;
            }
        }

        throw new FakerException(
            "Unable to generate a valid value for [{$method}] in {$this->maxRetries} attempts.",
        );
    }
}
