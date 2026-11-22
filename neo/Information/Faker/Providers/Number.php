<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Exceptions\FakerException;
use Neo\Information\Faker\Provider;

/**
 * Numbers, letters, picking from arrays and "#?%" masks.
 */
final class Number extends Provider
{
    public function numberBetween(int $min = 0, int $max = 2147483647): int
    {
        return $this->rng()->int($min, $max);
    }

    public function randomDigit(): int
    {
        return $this->rng()->int(0, 9);
    }

    public function randomDigitNotZero(): int
    {
        return $this->rng()->int(1, 9);
    }

    /**
     * Number with up to $digits digits; with $strict exactly $digits digits.
     */
    public function randomNumber(?int $digits = null, bool $strict = false): int
    {
        $digits ??= $this->randomDigitNotZero();

        if ($digits < 1 || $digits > 18) {
            throw new FakerException('randomNumber() supports 1 to 18 digits.');
        }

        $max = 10 ** $digits - 1;

        return $this->rng()->int($strict ? 10 ** ($digits - 1) : 0, $max);
    }

    /**
     * Float rounded to $decimals places (random 0-4 when null); $max is random when null.
     */
    public function randomFloat(?int $decimals = null, float $min = 0.0, ?float $max = null): float
    {
        $decimals ??= $this->rng()->int(0, 4);
        $max ??= (float) $this->randomNumber();

        return round($this->rng()->float($min, $max), max(0, $decimals));
    }

    public function randomLetter(): string
    {
        return chr($this->rng()->int(97, 122));
    }

    public function randomElement(array $array = ['a', 'b', 'c']): mixed
    {
        return $this->rng()->pick($array);
    }

    public function randomElements(array $array = ['a', 'b', 'c'], int $count = 1, bool $allowDuplicates = false): array
    {
        if (!$allowDuplicates && $count > count($array)) {
            throw new FakerException('Cannot get more unique elements than the array contains.');
        }

        return $this->rng()->pickMany($array, $count, $allowDuplicates);
    }

    public function shuffleArray(array $array = []): array
    {
        return $this->rng()->shuffle($array);
    }

    public function shuffleString(string $string = ''): string
    {
        return implode('', $this->rng()->shuffle(mb_str_split($string)));
    }

    /** "#" -> digit 0-9, "%" -> digit 1-9. */
    public function numerify(string $format = '###'): string
    {
        return parent::numerify($format);
    }

    /** "?" -> letter a-z. */
    public function lexify(string $format = '????'): string
    {
        return parent::lexify($format);
    }

    /** Both masks at once. */
    public function bothify(string $format = '## ??'): string
    {
        return parent::lexify(parent::numerify($format));
    }
}
