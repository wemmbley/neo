<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Datetime\Chronos;
use Neo\Information\Faker\Exceptions\FakerException;
use Neo\Information\Faker\Provider;

/**
 * Cards (valid Luhn numbers), IBANs (valid mod-97 checksum), currencies.
 * Everything here is test data and never matches a real account.
 */
final class Payment extends Provider
{
    private const CARDS = [
        'Visa' => ['prefixes' => ['4'], 'length' => 16],
        'MasterCard' => ['prefixes' => ['51', '52', '53', '54', '55'], 'length' => 16],
        'American Express' => ['prefixes' => ['34', '37'], 'length' => 15],
        'Discover' => ['prefixes' => ['6011', '65'], 'length' => 16],
        'Mir' => ['prefixes' => ['2200', '2201', '2202', '2203', '2204'], 'length' => 16],
    ];

    /** Country => BBAN segments: n = digits, a = uppercase letters. */
    private const IBAN = [
        'DE' => [['n', 18]],
        'GB' => [['a', 4], ['n', 14]],
        'NL' => [['a', 4], ['n', 10]],
        'PL' => [['n', 24]],
        'UA' => [['n', 25]],
        'ES' => [['n', 20]],
    ];

    private const CURRENCIES = ['USD', 'EUR', 'GBP', 'UAH', 'RUB', 'PLN', 'CHF', 'JPY', 'CNY', 'CAD', 'AUD'];

    public function creditCardType(): string
    {
        return (string) $this->rng()->pickKey(self::CARDS);
    }

    public function creditCardNumber(?string $type = null, bool $formatted = false, string $separator = '-'): string
    {
        $type ??= $this->creditCardType();
        $spec = self::CARDS[$type] ?? throw new FakerException("Unknown credit card type [{$type}].");

        $number = (string) $this->rng()->pick($spec['prefixes']);

        while (strlen($number) < $spec['length'] - 1) {
            $number .= $this->rng()->int(0, 9);
        }

        $number .= $this->luhnDigit($number);

        if (!$formatted) {
            return $number;
        }

        $groups = $spec['length'] === 15
            ? [substr($number, 0, 4), substr($number, 4, 6), substr($number, 10, 5)]
            : str_split($number, 4);

        return implode($separator, $groups);
    }

    public function creditCardExpirationDate(): Chronos
    {
        return $this->faker->dateTimeBetween('+1 month', '+5 years');
    }

    public function creditCardExpirationDateString(string $format = 'm/y'): string
    {
        return $this->creditCardExpirationDate()->format($format);
    }

    public function creditCardCvv(): string
    {
        return sprintf('%03d', $this->rng()->int(0, 999));
    }

    public function iban(?string $countryCode = null): string
    {
        $countryCode = strtoupper($countryCode ?? (string) $this->rng()->pickKey(self::IBAN));
        $segments = self::IBAN[$countryCode] ?? throw new FakerException("IBAN for country [{$countryCode}] is not supported.");

        $bban = '';
        foreach ($segments as [$kind, $length]) {
            for ($i = 0; $i < $length; $i++) {
                $bban .= $kind === 'n' ? (string) $this->rng()->int(0, 9) : chr($this->rng()->int(65, 90));
            }
        }

        $check = 98 - $this->mod97($this->numeric($bban . $countryCode . '00'));

        return $countryCode . sprintf('%02d', $check) . $bban;
    }

    public function currencyCode(): string
    {
        return (string) $this->rng()->pick(self::CURRENCIES);
    }

    /** Check digit that makes $partial + digit a valid Luhn number. */
    private function luhnDigit(string $partial): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($partial)) as $i => $char) {
            $digit = (int) $char;

            if ($i % 2 === 0) {
                $digit *= 2;
                $digit -= $digit > 9 ? 9 : 0;
            }

            $sum += $digit;
        }

        return (10 - $sum % 10) % 10;
    }

    /** Letters to numbers (A = 10 ... Z = 35), as the IBAN algorithm requires. */
    private function numeric(string $value): string
    {
        $result = '';

        foreach (str_split($value) as $char) {
            $result .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return $result;
    }

    private function mod97(string $digits): int
    {
        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder;
    }
}
