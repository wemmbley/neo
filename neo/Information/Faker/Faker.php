<?php

declare(strict_types=1);

namespace Neo\Information\Faker;

use Closure;
use Neo\Information\Faker\Exceptions\FakerException;
use Neo\Information\Faker\Modifiers\OptionalGenerator;
use Neo\Information\Faker\Modifiers\UniqueGenerator;
use Neo\Information\Faker\Modifiers\ValidGenerator;
use Neo\Information\Faker\Providers\Address;
use Neo\Information\Faker\Providers\Auth;
use Neo\Information\Faker\Providers\Boolean;
use Neo\Information\Faker\Providers\Color;
use Neo\Information\Faker\Providers\Company;
use Neo\Information\Faker\Providers\DateTime;
use Neo\Information\Faker\Providers\File;
use Neo\Information\Faker\Providers\Internet;
use Neo\Information\Faker\Providers\Number;
use Neo\Information\Faker\Providers\Payment;
use Neo\Information\Faker\Providers\Person;
use Neo\Information\Faker\Providers\Phone;
use Neo\Information\Faker\Providers\Text;
use Neo\Information\Faker\Providers\Uuid;

/**
 * Fake data generator.
 *
 * Every public method of a registered {@see Provider} is a formatter:
 * $faker->name(), $faker->unique()->email(), $faker->optional(0.3)->phoneNumber().
 * Providers are created lazily, on the first call of one of their formatters.
 * Providers added later override the formatters of earlier ones.
 *
 * Use through the {@see Fake} facade or the container; it holds no static state.
 */
class Faker
{
    /** Built-in providers, in registration order. */
    public const PROVIDERS = [
        Number::class,
        Boolean::class,
        Text::class,
        Person::class,
        Internet::class,
        Address::class,
        Phone::class,
        Company::class,
        DateTime::class,
        Color::class,
        Uuid::class,
        Payment::class,
        File::class,
        Auth::class,
    ];

    private Randomizer $randomizer;

    private Locale $locale;

    /** @var array<class-string<Provider>, Provider> */
    private array $providers = [];

    /** @var array<string, class-string<Provider>> formatter name => provider */
    private array $formatters = [];

    /** @var array<string, Closure> */
    private array $macros = [];

    private ?UniqueGenerator $unique = null;

    public function __construct(string $locale = Locale::FALLBACK, ?int $seed = null)
    {
        $this->locale = new Locale($locale);
        $this->randomizer = new Randomizer($seed);

        foreach (self::PROVIDERS as $provider) {
            $this->addProvider($provider);
        }
    }

    /**
     * Registers a provider class (or a ready instance built with this Faker).
     */
    public function addProvider(Provider|string $provider): static
    {
        if ($provider instanceof Provider) {
            $class = $provider::class;
            $this->providers[$class] = $provider;
        } elseif (is_subclass_of($provider, Provider::class)) {
            $class = $provider;
            unset($this->providers[$class]);
        } else {
            throw new FakerException('Faker provider must extend ' . Provider::class . '.');
        }

        foreach (get_class_methods($class) as $method) {
            if (!str_starts_with($method, '__')) {
                $this->formatters[$method] = $class;
            }
        }

        return $this;
    }

    /**
     * Adds a custom formatter; $this inside the closure is the Faker.
     */
    public function macro(string $name, Closure $callback): static
    {
        $this->macros[$name] = $callback->bindTo($this, static::class) ?? $callback;

        return $this;
    }

    /**
     * Restarts the random sequence (null = unpredictable) and resets unique().
     */
    public function seed(?int $seed = null): static
    {
        $this->randomizer->reseed($seed);
        $this->unique = null;

        return $this;
    }

    public function setLocale(string $code): static
    {
        $this->locale->use($code);

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale->code();
    }

    /**
     * Values are never repeated for the same formatter; reset with unique(true).
     */
    public function unique(bool $reset = false, int $maxRetries = 10000): UniqueGenerator
    {
        if ($reset || $this->unique === null) {
            $this->unique = new UniqueGenerator($this, $maxRetries);
        }

        return $this->unique;
    }

    /**
     * With probability $weight (0-1) returns the real value, otherwise $default.
     */
    public function optional(float $weight = 0.5, mixed $default = null): OptionalGenerator
    {
        return new OptionalGenerator($this, $weight, $default);
    }

    /**
     * Retries until $validator(value) is true.
     */
    public function valid(?Closure $validator = null, int $maxRetries = 10000): ValidGenerator
    {
        return new ValidGenerator($this, $validator, $maxRetries);
    }

    /** Source of randomness (for providers and macros). */
    public function rng(): Randomizer
    {
        return $this->randomizer;
    }

    /** Locale data by dot-path (for providers and macros). */
    public function lang(string $key, mixed $default = null): mixed
    {
        return $this->locale->get($key, $default);
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (isset($this->macros[$method])) {
            return ($this->macros[$method])(...$arguments);
        }

        $class = $this->formatters[$method]
            ?? throw new FakerException("Unknown Faker formatter [{$method}].");

        $provider = $this->providers[$class] ??= new $class($this);

        return $provider->{$method}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->__call($name, []);
    }
}
