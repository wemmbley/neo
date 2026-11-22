<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;
use Neo\Security\Crypt;

/**
 * Values for user and token tables.
 */
final class Auth extends Provider
{
    /** @var array<string, string> plain password => hash (hashing is slow, so it is done once per value) */
    private array $hashes = [];

    /** Hash of $plain made by Crypt::hash(), cached for the life of this Faker. */
    public function hashedPassword(string $plain = 'password'): string
    {
        return $this->hashes[$plain] ??= Crypt::hash($plain);
    }

    /** 10 characters like Laravel's remember_token; reproducible with seed(). */
    public function rememberToken(): string
    {
        return $this->alnum(10);
    }

    /** Random hex token of $length characters; reproducible with seed(). */
    public function token(int $length = 64): string
    {
        return $this->rng()->hex($length);
    }
}
