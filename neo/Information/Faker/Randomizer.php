<?php

declare(strict_types=1);

namespace Neo\Information\Faker;

use Neo\Information\Faker\Exceptions\FakerException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer as NativeRandomizer;

/**
 * Seedable source of randomness for every provider.
 *
 * Wraps the native {@see NativeRandomizer} with a Xoshiro256** engine, so the
 * same seed always yields the same sequence and the global mt_srand() state
 * is never touched.
 */
final class Randomizer
{
    private NativeRandomizer $engine;

    public function __construct(?int $seed = null)
    {
        $this->reseed($seed);
    }

    /**
     * Restarts the sequence. A null seed means "unpredictable".
     */
    public function reseed(?int $seed = null): void
    {
        $this->engine = new NativeRandomizer(new Xoshiro256StarStar($seed));
    }

    /**
     * Random integer in the inclusive range; the bounds may come in any order.
     */
    public function int(int $min = 0, int $max = PHP_INT_MAX): int
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return $this->engine->getInt($min, $max);
    }

    /**
     * Random float in [min, max); the bounds may come in any order.
     */
    public function float(float $min = 0.0, float $max = 1.0): float
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return $min === $max ? $min : $this->engine->getFloat($min, $max);
    }

    /**
     * True with the given probability (0.0 - 1.0).
     */
    public function chance(float $probability): bool
    {
        return $this->engine->nextFloat() < $probability;
    }

    /**
     * Random element of a non-empty array (keys are ignored).
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new FakerException('Cannot pick an element from an empty array.');
        }

        $items = array_values($items);

        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * Random key of a non-empty array.
     */
    public function pickKey(array $items): int|string
    {
        if ($items === []) {
            throw new FakerException('Cannot pick a key from an empty array.');
        }

        return $this->pick(array_keys($items));
    }

    /**
     * Several random elements, with or without repeats.
     */
    public function pickMany(array $items, int $count, bool $allowDuplicates = false): array
    {
        if ($count <= 0 || $items === []) {
            return [];
        }

        if ($allowDuplicates) {
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $result[] = $this->pick($items);
            }

            return $result;
        }

        return array_slice($this->shuffle($items), 0, $count);
    }

    public function shuffle(array $items): array
    {
        return $this->engine->shuffleArray(array_values($items));
    }

    public function bytes(int $length): string
    {
        return $this->engine->getBytes(max(1, $length));
    }

    /**
     * Random lowercase hex string of exactly $length characters.
     */
    public function hex(int $length): string
    {
        return substr(bin2hex($this->bytes((int) ceil($length / 2))), 0, $length);
    }
}
