<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;

/**
 * Emails, usernames, domains, URLs, IPs, MAC addresses, user agents.
 * Cyrillic names are transliterated through the locale table "internet.translit".
 */
final class Internet extends Provider
{
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
    ];

    public function email(): string
    {
        return $this->rng()->chance(0.7) ? $this->freeEmail() : $this->companyEmail();
    }

    /** Address on a reserved example domain: never reaches a real mailbox. */
    public function safeEmail(): string
    {
        return $this->userName() . '@example.' . $this->rng()->pick(['com', 'org', 'net']);
    }

    public function freeEmail(): string
    {
        return $this->userName() . '@' . $this->pickLang('internet.free_email_domains');
    }

    public function companyEmail(): string
    {
        return $this->userName() . '@' . $this->domainName();
    }

    public function userName(): string
    {
        $gender = $this->faker->gender();
        $first = $this->asciify($this->faker->firstName($gender));
        $last = $this->asciify($this->faker->lastName($gender));

        return (string) $this->rng()->pick([
            $first . '.' . $last,
            $first . '_' . $last,
            $first . $last,
            substr($first, 0, 1) . $last,
            $first . $this->rng()->int(1, 99),
        ]);
    }

    /**
     * Plain-text password (for the hashed one see Auth::hashedPassword()).
     */
    public function password(int $minLength = 6, int $maxLength = 20): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $length = $this->rng()->int($minLength, $maxLength);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[$this->rng()->int(0, strlen($chars) - 1)];
        }

        return $result;
    }

    public function domainWord(): string
    {
        return $this->asciify($this->faker->lastName('male')) ?: 'example';
    }

    public function tld(): string
    {
        return (string) $this->pickLang('internet.tlds');
    }

    public function domainName(): string
    {
        return $this->domainWord() . '.' . $this->tld();
    }

    public function url(): string
    {
        $url = 'https://' . $this->domainName();

        return $this->rng()->chance(0.5) ? $url . '/' . $this->slug(2) : $url;
    }

    public function slug(int $words = 4): string
    {
        $parts = array_filter(
            array_map(fn (string $word): string => $this->asciify($word), $this->faker->words($words)),
            static fn (string $word): bool => $word !== '',
        );

        return implode('-', $parts);
    }

    public function ipv4(): string
    {
        $first = $this->rng()->int(1, 223);
        $first = $first === 10 || $first === 127 ? $first + 1 : $first;

        return $first . '.' . $this->rng()->int(0, 255) . '.' . $this->rng()->int(0, 255) . '.' . $this->rng()->int(1, 254);
    }

    public function localIpv4(): string
    {
        return $this->rng()->chance(0.5)
            ? '192.168.' . $this->rng()->int(0, 255) . '.' . $this->rng()->int(1, 254)
            : '10.' . $this->rng()->int(0, 255) . '.' . $this->rng()->int(0, 255) . '.' . $this->rng()->int(1, 254);
    }

    public function ipv6(): string
    {
        $groups = [];

        for ($i = 0; $i < 8; $i++) {
            $groups[] = dechex($this->rng()->int(0, 65535));
        }

        return implode(':', $groups);
    }

    /** Unicast MAC address. */
    public function macAddress(): string
    {
        $bytes = [sprintf('%02x', $this->rng()->int(0, 127) * 2)];

        for ($i = 1; $i < 6; $i++) {
            $bytes[] = sprintf('%02x', $this->rng()->int(0, 255));
        }

        return implode(':', $bytes);
    }

    public function userAgent(): string
    {
        return (string) $this->rng()->pick(self::USER_AGENTS);
    }

    /**
     * Lowercase latin letters and digits only.
     */
    private function asciify(string $text): string
    {
        $text = mb_strtolower($text);
        $map = $this->lang('internet.translit', []);

        if (is_array($map) && $map !== []) {
            $text = strtr($text, $map);
        }

        return (string) preg_replace('/[^a-z0-9]+/', '', $text);
    }
}
