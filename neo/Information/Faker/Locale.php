<?php

declare(strict_types=1);

namespace Neo\Information\Faker;

use Neo\Information\Faker\Exceptions\FakerException;

/**
 * Locale data of the Faker (names, cities, formats...).
 *
 * This is the single place that reads locale files and resolves dot-paths,
 * so swapping it for LangProcessor / DotNotation touches nothing else.
 * Missing keys fall back to the English data.
 */
final class Locale
{
    public const FALLBACK = 'en';

    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    private string $code;

    public function __construct(string $code = self::FALLBACK, private readonly string $directory = __DIR__ . '/Lang')
    {
        $this->use($code);
    }

    public function code(): string
    {
        return $this->code;
    }

    /**
     * Switches the active locale and loads its data file.
     */
    public function use(string $code): void
    {
        $this->load($code);
        $this->code = $code;
    }

    /**
     * Reads a value by dot-path, e.g. "person.first_names.male".
     */
    public function get(string $key, mixed $default = null): mixed
    {
        foreach (array_unique([$this->code, self::FALLBACK]) as $code) {
            $found = false;
            $value = $this->dig($this->load($code), $key, $found);

            if ($found) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $code): array
    {
        if (isset($this->loaded[$code])) {
            return $this->loaded[$code];
        }

        $file = $this->directory . '/' . $code . '.php';

        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code) || !is_file($file)) {
            throw new FakerException("Faker locale [{$code}] is not supported.");
        }

        return $this->loaded[$code] = require $file;
    }

    private function dig(array $data, string $key, bool &$found): mixed
    {
        $cursor = $data;
        $found = false;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        $found = true;

        return $cursor;
    }
}
