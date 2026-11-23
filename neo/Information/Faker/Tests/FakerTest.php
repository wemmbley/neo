<?php

declare(strict_types=1);

use Neo\Information\Datetime\Chronos;
use Neo\Information\Faker\Exceptions\FakerException;
use Neo\Information\Faker\Exceptions\UniqueLimitException;
use Neo\Information\Faker\Faker;
use Neo\Security\Crypt\CryptManager;

/**
 * Runs the callback and returns the class of the thrown exception (or null).
 */
function thrownBy(callable $callback): ?string
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e::class;
    }

    return null;
}

function luhnIsValid(string $number): bool
{
    $sum = 0;

    foreach (array_reverse(str_split($number)) as $i => $char) {
        $digit = (int) $char;

        if ($i % 2 === 1) {
            $digit *= 2;
            $digit -= $digit > 9 ? 9 : 0;
        }

        $sum += $digit;
    }

    return $sum % 10 === 0;
}

// --- core -------------------------------------------------------------------

test('the same seed gives the same sequence', function () {
    $a = new Faker('en', 42);
    $b = new Faker('en', 42);

    $first = [$a->name(), $a->email(), $a->uuid(), $a->numberBetween(1, 1000000), $a->text(120)];
    $second = [$b->name(), $b->email(), $b->uuid(), $b->numberBetween(1, 1000000), $b->text(120)];

    expect($first)->toBe($second);
});

test('different seeds give different sequences', function () {
    expect((new Faker('en', 1))->uuid() === (new Faker('en', 2))->uuid())->toBeFalse();
});

test('seed() restarts the sequence', function () {
    $faker = new Faker('en', 5);
    $before = $faker->uuid();

    $faker->seed(5);

    expect($faker->uuid())->toBe($before);
});

test('an unknown formatter throws FakerException', function () {
    expect(thrownBy(fn () => (new Faker())->doesNotExist()))->toBe(FakerException::class);
});

test('an unknown locale throws FakerException', function () {
    expect(thrownBy(fn () => new Faker('xx')))->toBe(FakerException::class);
});

test('formatters work as properties too', function () {
    $faker = new Faker('en', 3);

    expect(is_string($faker->email))->toBeTrue();
});

test('macros get the faker as $this', function () {
    $faker = (new Faker('en', 3))->macro('shout', function (string $text): string {
        return strtoupper($text) . '! ' . $this->getLocale();
    });

    expect($faker->shout('hi'))->toBe('HI! en');
});

test('a custom provider overrides a built-in formatter', function () {
    $faker = new Faker('en', 3);
    $faker->addProvider(new class ($faker) extends Neo\Information\Faker\Provider {
        public function name(?string $gender = null): string
        {
            return 'Neo';
        }
    });

    expect($faker->name())->toBe('Neo');
});

// --- modifiers --------------------------------------------------------------

test('unique() never repeats a value', function () {
    $faker = new Faker('en', 9);
    $values = [];

    for ($i = 0; $i < 50; $i++) {
        $values[] = $faker->unique()->numberBetween(1, 50);
    }

    expect(count(array_unique($values)))->toBe(50);
});

test('unique() throws when the values run out', function () {
    $faker = new Faker('en', 9);

    $faker->unique(true, 200);

    for ($i = 0; $i < 3; $i++) {
        $faker->unique()->numberBetween(1, 3);
    }

    expect(thrownBy(fn () => $faker->unique()->numberBetween(1, 3)))->toBe(UniqueLimitException::class);
});

test('unique(true) resets the set', function () {
    $faker = new Faker('en', 9);

    for ($i = 0; $i < 3; $i++) {
        $faker->unique()->numberBetween(1, 3);
    }

    $faker->unique(true);

    expect(is_int($faker->unique()->numberBetween(1, 3)))->toBeTrue();
});

test('optional(0.0) returns the default and optional(1.0) the value', function () {
    $faker = new Faker('en', 9);

    expect($faker->optional(0.0)->name())->toBe(null);
    expect($faker->optional(0.0, 'none')->name())->toBe('none');
    expect(is_string($faker->optional(1.0)->name()))->toBeTrue();
});

test('valid() retries until the validator accepts', function () {
    $faker = new Faker('en', 9);

    for ($i = 0; $i < 20; $i++) {
        expect($faker->valid(fn (int $n): bool => $n % 2 === 0)->numberBetween(1, 100) % 2)->toBe(0);
    }
});

test('valid() gives up after maxRetries', function () {
    $faker = new Faker('en', 9);

    expect(thrownBy(fn () => $faker->valid(fn (): bool => false, 10)->numberBetween(1, 5)))->toBe(FakerException::class);
});

// --- providers --------------------------------------------------------------

test('numbers stay in range', function () {
    $faker = new Faker('en', 11);

    for ($i = 0; $i < 200; $i++) {
        $n = $faker->numberBetween(5, 10);
        expect($n >= 5 && $n <= 10)->toBeTrue();
    }

    expect(strlen((string) $faker->randomNumber(6, true)))->toBe(6);
    expect($faker->randomDigitNotZero() > 0)->toBeTrue();
});

test('randomElements without duplicates never exceeds the array', function () {
    $faker = new Faker('en', 11);

    expect(count($faker->randomElements([1, 2, 3, 4], 3)))->toBe(3);
    expect(thrownBy(fn () => $faker->randomElements([1, 2], 5)))->toBe(FakerException::class);
});

test('numerify, lexify and bothify fill the masks', function () {
    $faker = new Faker('en', 11);

    expect(preg_match('/^\d{3}$/', $faker->numerify('###')))->toBe(1);
    expect(preg_match('/^[1-9]\d$/', $faker->numerify('%#')))->toBe(1);
    expect(preg_match('/^[a-z]{4}$/', $faker->lexify('????')))->toBe(1);
    expect(preg_match('/^\d\d [a-z]{2}$/', $faker->bothify('## ??')))->toBe(1);
});

test('text() is never longer than the limit', function () {
    $faker = new Faker('ru', 11);

    foreach ([5, 20, 100, 400] as $limit) {
        expect(mb_strlen($faker->text($limit)) <= $limit)->toBeTrue();
    }
});

test('emails are valid addresses', function () {
    foreach (['en', 'ru'] as $locale) {
        $faker = new Faker($locale, 21);

        for ($i = 0; $i < 50; $i++) {
            expect(filter_var($faker->email(), FILTER_VALIDATE_EMAIL) !== false)->toBeTrue();
            expect(filter_var($faker->safeEmail(), FILTER_VALIDATE_EMAIL) !== false)->toBeTrue();
        }
    }
});

test('network values have the right shape', function () {
    $faker = new Faker('en', 21);

    expect(filter_var($faker->ipv4(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)->toBeTrue();
    expect(filter_var($faker->ipv6(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)->toBeTrue();
    expect(filter_var($faker->macAddress(), FILTER_VALIDATE_MAC) !== false)->toBeTrue();
    expect(filter_var($faker->url(), FILTER_VALIDATE_URL) !== false)->toBeTrue();
});

test('russian names are cyrillic and gender-consistent', function () {
    $faker = new Faker('ru', 31);

    expect(preg_match('/^[А-ЯЁа-яё\s-]+$/u', $faker->name()))->toBe(1);

    for ($i = 0; $i < 30; $i++) {
        expect(mb_substr($faker->lastName('female'), -1))->toBe('а');
        expect(mb_substr($faker->middleName('female'), -3))->toBe('вна');
        expect(mb_substr($faker->middleName('male'), -2))->toBe('ич');
    }
});

test('english falls back for keys a locale does not have', function () {
    $faker = new Faker('ru', 31);

    expect(is_string($faker->suffix()))->toBeTrue();
});

test('phones follow the locale format', function () {
    expect(preg_match('/^(\+7|8) /', (new Faker('ru', 41))->phoneNumber()))->toBe(1);
    expect(preg_match('/^\+79\d{9}$/', (new Faker('ru', 41))->e164PhoneNumber()))->toBe(1);
    expect(preg_match('/^\+1\d{10}$/', (new Faker('en', 41))->e164PhoneNumber()))->toBe(1);
});

test('uuid is a valid v4', function () {
    expect(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (new Faker('en', 51))->uuid()))->toBe(1);
});

test('credit card numbers pass the Luhn check', function () {
    $faker = new Faker('en', 61);

    foreach (['Visa', 'MasterCard', 'American Express', 'Discover', 'Mir'] as $type) {
        for ($i = 0; $i < 20; $i++) {
            expect(luhnIsValid($faker->creditCardNumber($type)))->toBeTrue();
        }
    }

    expect(preg_match('/^\d{4}-\d{6}-\d{5}$/', $faker->creditCardNumber('American Express', true)))->toBe(1);
});

test('IBANs pass the mod-97 check', function () {
    $faker = new Faker('en', 61);

    foreach (['DE', 'GB', 'NL', 'PL', 'UA', 'ES'] as $country) {
        $iban = $faker->iban($country);
        $moved = substr($iban, 4) . substr($iban, 0, 4);
        $digits = '';

        foreach (str_split($moved) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $rem = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rem = (int) ($rem . $chunk) % 97;
        }

        expect($rem)->toBe(1);
    }
});

test('dates stay inside the requested range', function () {
    $faker = new Faker('en', 71);
    $start = Chronos::parse('2020-01-01')->getTimestamp();
    $end = Chronos::parse('2020-12-31')->getTimestamp();

    for ($i = 0; $i < 50; $i++) {
        $ts = $faker->dateTimeBetween('2020-01-01', '2020-12-31')->getTimestamp();
        expect($ts >= $start && $ts <= $end)->toBeTrue();
    }

    expect($faker->dateTime() instanceof Chronos)->toBeTrue();
});

test('hashedPassword is verifiable and hashed once per value', function () {
    $faker = new Faker('en', 81);
    $hash = $faker->hashedPassword();

    expect(CryptManager::verify('password', $hash))->toBeTrue();
    expect($faker->hashedPassword())->toBe($hash);
    expect($faker->hashedPassword('secret') === $hash)->toBeFalse();
});

test('rememberToken has 10 characters and token the requested length', function () {
    $faker = new Faker('en', 81);

    expect(strlen($faker->rememberToken()))->toBe(10);
    expect(strlen($faker->token(40)))->toBe(40);
});

test('the Laravel user factory case', function () {
    $faker = new Faker('en', 91);

    $row = [
        'name' => $faker->name(),
        'email' => $faker->unique()->safeEmail(),
        'email_verified_at' => Chronos::now(),
        'password' => $faker->hashedPassword(),
        'remember_token' => $faker->rememberToken(),
    ];

    expect(count($row))->toBe(5);
    expect(filter_var($row['email'], FILTER_VALIDATE_EMAIL) !== false)->toBeTrue();
});
