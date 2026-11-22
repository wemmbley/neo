<?php

declare(strict_types=1);

namespace Neo\Information\Faker;

use Neo\Information\Faker\Exceptions\FakerException;

/**
 * Base class of every formatter group.
 *
 * Every PUBLIC method of a subclass becomes a Faker formatter
 * ($faker->methodName()). Protected helpers are not exposed.
 */
abstract class Provider
{
    public function __construct(protected readonly Faker $faker) {}

    protected function rng(): Randomizer
    {
        return $this->faker->rng();
    }

    protected function lang(string $key, mixed $default = null): mixed
    {
        return $this->faker->lang($key, $default);
    }

    /**
     * Locale list by key; fails loudly if the locale file has no such list.
     */
    protected function listLang(string $key): array
    {
        $list = $this->lang($key);

        if (!is_array($list) || $list === []) {
            throw new FakerException("Faker locale data [{$key}] is missing or empty.");
        }

        return $list;
    }

    protected function pickLang(string $key): mixed
    {
        return $this->rng()->pick($this->listLang($key));
    }

    /**
     * "#" -> digit 0-9, "%" -> digit 1-9.
     */
    protected function numerify(string $format): string
    {
        return (string) preg_replace_callback(
            '/[#%]/',
            fn (array $m): string => (string) ($m[0] === '#' ? $this->rng()->int(0, 9) : $this->rng()->int(1, 9)),
            $format,
        );
    }

    /**
     * "?" -> letter a-z.
     */
    protected function lexify(string $format): string
    {
        return (string) preg_replace_callback(
            '/\?/',
            fn (): string => chr($this->rng()->int(97, 122)),
            $format,
        );
    }

    /**
     * Replaces {placeholders}; resolvers run only for placeholders that are present.
     *
     * @param array<string, callable(): mixed> $resolvers
     */
    protected function fill(string $template, array $resolvers): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => isset($resolvers[$m[1]]) ? (string) $resolvers[$m[1]]() : $m[0],
            $template,
        );
    }

    /**
     * Random [a-zA-Z0-9] string.
     */
    protected function alnum(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[$this->rng()->int(0, 61)];
        }

        return $result;
    }
}
