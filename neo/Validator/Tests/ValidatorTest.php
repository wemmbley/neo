<?php

declare(strict_types=1);

namespace Neo\Validator\Tests;

use InvalidArgumentException;
use Neo\Validator\ValidationException;
use Neo\Validator\Validator;

describe('Validator', function () {

    beforeEach(function () {
        Validator::flushExtensions();
    });

    afterEach(function () {
        Validator::flushExtensions();
    });

    describe('Construction', function () {

        it('creates validator through make()', function () {
            $validator = Validator::make(
                ['name' => 'Neo'],
                ['name' => 'required|string']
            );

            expect($validator)->toBeInstanceOf(Validator::class);
        });

        it('passes valid data', function () {
            $validator = Validator::make(
                ['name' => 'Neo'],
                ['name' => 'required|string']
            );

            expect($validator->passes())->toBeTrue();
        });

        it('fails invalid data', function () {
            $validator = Validator::make(
                ['name' => 123],
                ['name' => 'string'],
                ['string' => ':attribute должно быть строкой']
            );

            expect($validator->fails())->toBeTrue();
        });

        it('returns an empty error array for valid data', function () {
            $validator = Validator::make(
                ['name' => 'Neo'],
                ['name' => 'string']
            );

            expect($validator->errors())->toBe([]);
        });

        it('returns errors indexed by attribute', function () {
            $validator = Validator::make(
                ['name' => 123],
                ['name' => 'string'],
                ['string' => 'Ошибка :attribute']
            );

            $errors = $validator->errors();

            expect(array_key_exists('name', $errors))->toBeTrue();
            expect($errors['name'][0])->toBe('Ошибка name');
        });
    });

    describe('Required and presence rules', function () {

        it('accepts a present non-empty value with required', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'required']
                )->passes()
            )->toBeTrue();
        });

        it('rejects a missing required field', function () {
            expect(
                Validator::make(
                    [],
                    ['name' => 'required'],
                    ['required' => ':attribute обязательно']
                )->fails()
            )->toBeTrue();
        });

        it('rejects null required values', function () {
            expect(
                Validator::make(
                    ['name' => null],
                    ['name' => 'required'],
                    ['required' => 'required']
                )->fails()
            )->toBeTrue();
        });

        it('rejects empty strings with required', function () {
            expect(
                Validator::make(
                    ['name' => ''],
                    ['name' => 'required'],
                    ['required' => 'required']
                )->fails()
            )->toBeTrue();
        });

        it('rejects whitespace-only strings with required', function () {
            expect(
                Validator::make(
                    ['name' => '   '],
                    ['name' => 'required'],
                    ['required' => 'required']
                )->fails()
            )->toBeTrue();
        });

        it('rejects empty arrays with required', function () {
            expect(
                Validator::make(
                    ['items' => []],
                    ['items' => 'required'],
                    ['required' => 'required']
                )->fails()
            )->toBeTrue();
        });

        it('accepts zero with required', function () {
            expect(
                Validator::make(
                    ['amount' => 0],
                    ['amount' => 'required']
                )->passes()
            )->toBeTrue();
        });

        it('accepts false with required', function () {
            expect(
                Validator::make(
                    ['active' => false],
                    ['active' => 'required']
                )->passes()
            )->toBeTrue();
        });

        it('checks field presence with present', function () {
            expect(
                Validator::make(
                    ['name' => null],
                    ['name' => 'present']
                )->passes()
            )->toBeTrue();
        });

        it('rejects missing fields with present', function () {
            expect(
                Validator::make(
                    [],
                    ['name' => 'present'],
                    ['present' => 'present']
                )->fails()
            )->toBeTrue();
        });

        it('accepts missing fields with filled', function () {
            expect(
                Validator::make(
                    [],
                    ['name' => 'filled']
                )->passes()
            )->toBeTrue();
        });

        it('rejects empty present fields with filled', function () {
            expect(
                Validator::make(
                    ['name' => ''],
                    ['name' => 'filled'],
                    ['filled' => 'filled']
                )->fails()
            )->toBeTrue();
        });

        it('accepts non-empty present fields with filled', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'filled']
                )->passes()
            )->toBeTrue();
        });

        it('accepts valid accepted values', function () {
            foreach (['yes', 'on', '1', 1, true, 'true'] as $value) {
                expect(
                    Validator::make(
                        ['terms' => $value],
                        ['terms' => 'accepted']
                    )->passes()
                )->toBeTrue();
            }
        });

        it('rejects invalid accepted values', function () {
            foreach (['no', 'off', '0', 0, false, null] as $value) {
                expect(
                    Validator::make(
                        ['terms' => $value],
                        ['terms' => 'accepted'],
                        ['accepted' => 'accepted']
                    )->fails()
                )->toBeTrue();
            }
        });
    });

    describe('Meta rules', function () {

        it('skips missing fields with sometimes', function () {
            expect(
                Validator::make(
                    [],
                    ['name' => 'sometimes|required']
                )->passes()
            )->toBeTrue();
        });

        it('validates present fields with sometimes', function () {
            expect(
                Validator::make(
                    ['name' => ''],
                    ['name' => 'sometimes|required'],
                    ['required' => 'required']
                )->fails()
            )->toBeTrue();
        });

        it('allows null with nullable', function () {
            expect(
                Validator::make(
                    ['name' => null],
                    ['name' => 'nullable|string']
                )->passes()
            )->toBeTrue();
        });

        it('does not skip null without nullable', function () {
            expect(
                Validator::make(
                    ['name' => null],
                    ['name' => 'string'],
                    ['string' => 'string']
                )->fails()
            )->toBeTrue();
        });

        it('skips ordinary rules for missing fields', function () {
            expect(
                Validator::make(
                    [],
                    ['name' => 'string|email']
                )->passes()
            )->toBeTrue();
        });

        it('skips ordinary rules for empty strings', function () {
            expect(
                Validator::make(
                    ['name' => ''],
                    ['name' => 'string|email']
                )->passes()
            )->toBeTrue();
        });

        it('does not skip implicit rules for empty strings', function () {
            expect(
                Validator::make(
                    ['name' => ''],
                    ['name' => 'required|string'],
                    ['required' => 'required']
                )->fails()
            )->toBeTrue();
        });

        it('stops field validation after the first error with bail', function () {
            $validator = Validator::make(
                ['name' => 123],
                ['name' => 'bail|string|email'],
                [
                    'string' => 'string error',
                    'email' => 'email error',
                ]
            );

            $errors = $validator->errors();

            expect(count($errors['name']))->toBe(1);
            expect($errors['name'][0])->toBe('string error');
        });

        it('collects multiple errors without bail', function () {
            $validator = Validator::make(
                ['name' => 123],
                ['name' => 'string|email'],
                [
                    'string' => 'string error',
                    'email' => 'email error',
                ]
            );

            $errors = $validator->errors();

            expect(count($errors['name']))->toBe(2);
        });
    });

    describe('Primitive types', function () {

        it('validates string', function () {
            expect(
                Validator::make(['value' => 'Neo'], ['value' => 'string'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(['value' => 123], ['value' => 'string'])->fails()
            )->toBeTrue();
        });

        it('validates integer', function () {
            foreach ([123, -123, '123', '-123', '0'] as $value) {
                expect(
                    Validator::make(['value' => $value], ['value' => 'integer'])->passes()
                )->toBeTrue();
            }

            foreach ([1.5, '1.5', 'abc', true] as $value) {
                expect(
                    Validator::make(['value' => $value], ['value' => 'integer'])->fails()
                )->toBeTrue();
            }
        });

        it('validates numeric', function () {
            foreach ([123, 1.5, '123', '1.5', '-2.5'] as $value) {
                expect(
                    Validator::make(['value' => $value], ['value' => 'numeric'])->passes()
                )->toBeTrue();
            }

            expect(
                Validator::make(['value' => 'abc'], ['value' => 'numeric'])->fails()
            )->toBeTrue();
        });

        it('validates boolean', function () {
            foreach ([true, false, 1, 0, '1', '0'] as $value) {
                expect(
                    Validator::make(['value' => $value], ['value' => 'boolean'])->passes()
                )->toBeTrue();
            }

            foreach (['true', 'false', 'yes', 2, null] as $value) {
                expect(
                    Validator::make(['value' => $value], ['value' => 'boolean'])->fails()
                )->toBeTrue();
            }
        });

        it('validates array', function () {
            expect(
                Validator::make(['value' => []], ['value' => 'array'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(['value' => 'array'], ['value' => 'array'])->fails()
            )->toBeTrue();
        });
    });

    describe('Format rules', function () {

        it('validates email', function () {
            expect(
                Validator::make(['email' => 'neo@example.com'], ['email' => 'email'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['email' => 'invalid'],
                    ['email' => 'email'],
                    ['email' => 'invalid email']
                )->fails()
            )->toBeTrue();
        });

        it('validates url', function () {
            expect(
                Validator::make(['url' => 'https://example.com'], ['url' => 'url'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['url' => 'example.com'],
                    ['url' => 'url'],
                    ['url' => 'invalid url']
                )->fails()
            )->toBeTrue();
        });

        it('validates ip addresses', function () {
            expect(
                Validator::make(['ip' => '127.0.0.1'], ['ip' => 'ip'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['ip' => '999.999.999.999'],
                    ['ip' => 'ip'],
                    ['ip' => 'invalid ip']
                )->fails()
            )->toBeTrue();
        });

        it('validates json', function () {
            expect(
                Validator::make(['data' => '{"name":"Neo"}'], ['data' => 'json'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['data' => '{invalid'],
                    ['data' => 'json'],
                    ['json' => 'invalid json']
                )->fails()
            )->toBeTrue();
        });

        it('validates uuid format', function () {
            expect(
                Validator::make(
                    ['id' => '550e8400-e29b-41d4-a716-446655440000'],
                    ['id' => 'uuid']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['id' => 'invalid-uuid'],
                    ['id' => 'uuid'],
                    ['uuid' => 'invalid uuid']
                )->fails()
            )->toBeTrue();
        });

        it('validates dates', function () {
            expect(
                Validator::make(['date' => '2026-11-03'], ['date' => 'date'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['date' => 'not-a-date'],
                    ['date' => 'date'],
                    ['date' => 'invalid date']
                )->fails()
            )->toBeTrue();
        });

        it('rejects relative date words without digits', function () {
            foreach (['now', 'tomorrow', 'x'] as $value) {
                expect(
                    Validator::make(
                        ['date' => $value],
                        ['date' => 'date'],
                        ['date' => 'invalid date']
                    )->fails()
                )->toBeTrue();
            }
        });

        it('validates date_format', function () {
            expect(
                Validator::make(
                    ['date' => '2026-11-03'],
                    ['date' => 'date_format:Y-m-d']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['date' => '03.11.2026'],
                    ['date' => 'date_format:Y-m-d'],
                    ['date_format' => 'invalid date format']
                )->fails()
            )->toBeTrue();
        });

        it('rejects invalid calendar dates for date_format', function () {
            expect(
                Validator::make(
                    ['date' => '2026-02-31'],
                    ['date' => 'date_format:Y-m-d'],
                    ['date_format' => 'invalid date']
                )->fails()
            )->toBeTrue();
        });

        it('throws when date_format has no format', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['date' => '2026-11-03'],
                    ['date' => 'date_format']
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });

        it('validates alpha', function () {
            expect(
                Validator::make(['value' => 'Привет'], ['value' => 'alpha'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['value' => 'Neo123'],
                    ['value' => 'alpha'],
                    ['alpha' => 'invalid alpha']
                )->fails()
            )->toBeTrue();
        });

        it('validates alpha_num', function () {
            expect(
                Validator::make(['value' => 'Neo123'], ['value' => 'alpha_num'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['value' => 'Neo_123'],
                    ['value' => 'alpha_num'],
                    ['alpha_num' => 'invalid']
                )->fails()
            )->toBeTrue();
        });

        it('validates alpha_dash', function () {
            expect(
                Validator::make(['value' => 'Neo_123-test'], ['value' => 'alpha_dash'])->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['value' => 'Neo!'],
                    ['value' => 'alpha_dash'],
                    ['alpha_dash' => 'invalid']
                )->fails()
            )->toBeTrue();
        });
    });

    describe('Size rules', function () {

        it('validates minimum string length', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'min:3'],
                    ['min' => ['numeric' => 'numeric', 'string' => 'too short', 'array' => 'array']]
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['name' => 'Ne'],
                    ['name' => 'min:3'],
                    ['min' => ['numeric' => 'numeric', 'string' => 'too short', 'array' => 'array']]
                )->fails()
            )->toBeTrue();
        });

        it('validates maximum string length', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'max:3']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['name' => 'Neo4'],
                    ['name' => 'max:3'],
                    ['max' => ['numeric' => 'numeric', 'string' => 'too long', 'array' => 'array']]
                )->fails()
            )->toBeTrue();
        });

        it('validates exact string size', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'size:3']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['name' => 'Neo4'],
                    ['name' => 'size:3'],
                    ['size' => ['numeric' => 'numeric', 'string' => 'wrong size', 'array' => 'array']]
                )->fails()
            )->toBeTrue();
        });

        it('validates numeric minimum and maximum', function () {
            expect(
                Validator::make(
                    ['age' => '18'],
                    ['age' => 'integer|min:18|max:100']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['age' => '17'],
                    ['age' => 'integer|min:18'],
                    ['min' => ['numeric' => 'too small', 'string' => 'string', 'array' => 'array']]
                )->fails()
            )->toBeTrue();
        });

        it('uses string length when numeric rule is absent', function () {
            expect(
                Validator::make(
                    ['value' => '1234'],
                    ['value' => 'min:4']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['value' => '1234'],
                    ['value' => 'min:5'],
                    ['min' => [
                        'numeric' => 'numeric',
                        'string' => 'string',
                        'array' => 'array',
                    ]]
                )->fails()
            )->toBeTrue();
        });

        it('validates array count', function () {
            expect(
                Validator::make(
                    ['items' => [1, 2, 3]],
                    ['items' => 'min:2|max:3']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['items' => [1]],
                    ['items' => 'min:2'],
                    ['min' => ['numeric' => 'numeric', 'string' => 'string', 'array' => 'too few']]
                )->fails()
            )->toBeTrue();
        });

        it('validates between', function () {
            expect(
                Validator::make(
                    ['age' => 25],
                    ['age' => 'numeric|between:18,30']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['age' => 35],
                    ['age' => 'numeric|between:18,30'],
                    ['between' => ['numeric' => 'out of range', 'string' => 'string', 'array' => 'array']]
                )->fails()
            )->toBeTrue();
        });

        it('validates digits', function () {
            expect(
                Validator::make(
                    ['code' => '12345'],
                    ['code' => 'digits:5']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['code' => '1234'],
                    ['code' => 'digits:5'],
                    ['digits' => 'wrong number of digits']
                )->fails()
            )->toBeTrue();
        });

        it('rejects non-numeric size parameters', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['age' => 10],
                    ['age' => 'min:abc']
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });
    });

    describe('Comparison rules', function () {

        it('validates in', function () {
            expect(
                Validator::make(
                    ['role' => 'admin'],
                    ['role' => 'in:admin,user']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['role' => 'guest'],
                    ['role' => 'in:admin,user'],
                    ['in' => 'invalid role']
                )->fails()
            )->toBeTrue();
        });

        it('validates not_in', function () {
            expect(
                Validator::make(
                    ['role' => 'guest'],
                    ['role' => 'not_in:admin,user']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['role' => 'admin'],
                    ['role' => 'not_in:admin,user'],
                    ['not_in' => 'forbidden role']
                )->fails()
            )->toBeTrue();
        });

        it('validates same fields', function () {
            expect(
                Validator::make(
                    ['password' => 'secret', 'copy' => 'secret'],
                    ['password' => 'same:copy']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['password' => 'secret', 'copy' => 'different'],
                    ['password' => 'same:copy'],
                    ['same' => 'values differ']
                )->fails()
            )->toBeTrue();
        });

        it('validates different fields', function () {
            expect(
                Validator::make(
                    ['password' => 'secret', 'old_password' => 'old'],
                    ['password' => 'different:old_password']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['password' => 'secret', 'old_password' => 'secret'],
                    ['password' => 'different:old_password'],
                    ['different' => 'values must differ']
                )->fails()
            )->toBeTrue();
        });

        it('validates confirmed fields', function () {
            expect(
                Validator::make(
                    [
                        'password' => 'secret',
                        'password_confirmation' => 'secret',
                    ],
                    ['password' => 'confirmed']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    [
                        'password' => 'secret',
                        'password_confirmation' => 'different',
                    ],
                    ['password' => 'confirmed'],
                    ['confirmed' => 'confirmation mismatch']
                )->fails()
            )->toBeTrue();
        });

        it('supports dot notation in same', function () {
            expect(
                Validator::make(
                    [
                        'user' => [
                            'password' => 'secret',
                            'confirmation' => 'secret',
                        ],
                    ],
                    ['user.password' => 'same:user.confirmation']
                )->passes()
            )->toBeTrue();
        });

        it('throws when same has no field parameter', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['value' => 'test'],
                    ['value' => 'same']
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });
    });

    describe('Pattern rules', function () {

        it('validates regex', function () {
            expect(
                Validator::make(
                    ['code' => 'ABC123'],
                    ['code' => 'regex:/^[A-Z0-9]+$/']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['code' => 'abc'],
                    ['code' => 'regex:/^[A-Z]+$/'],
                    ['regex' => 'invalid format']
                )->fails()
            )->toBeTrue();
        });

        it('preserves pipes and commas inside regex patterns', function () {
            expect(
                Validator::make(
                    ['value' => 'foo,bar'],
                    ['value' => ['regex:/^(foo|bar),bar$/']]
                )->passes()
            )->toBeTrue();
        });

        it('throws when regex has no pattern', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['value' => 'test'],
                    ['value' => 'regex']
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });

        it('throws for an invalid regex pattern', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['value' => 'test'],
                    ['value' => 'regex:/[/']
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });

        it('validates starts_with', function () {
            expect(
                Validator::make(
                    ['url' => 'https://example.com'],
                    ['url' => 'starts_with:http://,https://']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['url' => 'ftp://example.com'],
                    ['url' => 'starts_with:http://,https://'],
                    ['starts_with' => 'invalid prefix']
                )->fails()
            )->toBeTrue();
        });

        it('validates ends_with', function () {
            expect(
                Validator::make(
                    ['file' => 'image.png'],
                    ['file' => 'ends_with:png,jpg']
                )->passes()
            )->toBeTrue();

            expect(
                Validator::make(
                    ['file' => 'image.gif'],
                    ['file' => 'ends_with:png,jpg'],
                    ['ends_with' => 'invalid extension']
                )->fails()
            )->toBeTrue();
        });
    });

    describe('Closures', function () {

        it('supports custom closure rules', function () {
            $validator = Validator::make(
                ['code' => 'x'],
                [
                    'code' => [
                        'required',
                        fn ($attribute, $value, $fail) => $value === 'x'
                            || $fail(':attribute неверен'),
                    ],
                ]
            );

            expect($validator->passes())->toBeTrue();
        });

        it('collects errors from a failing closure', function () {
            $validator = Validator::make(
                ['code' => 'wrong'],
                [
                    'code' => [
                        fn ($attribute, $value, $fail) => $fail(':attribute неверен'),
                    ],
                ]
            );

            $errors = $validator->errors();

            expect($errors['code'][0])->toBe('code неверен');
        });

        it('passes attribute value and fail callback to closure', function () {
            $received = [];

            Validator::make(
                ['name' => 'Neo'],
                [
                    'name' => [
                        function ($attribute, $value, $fail) use (&$received) {
                            $received = [$attribute, $value, is_callable($fail)];
                        },
                    ],
                ]
            )->passes();

            expect($received[0])->toBe('name');
            expect($received[1])->toBe('Neo');
            expect($received[2])->toBeTrue();
        });

        it('allows multiple closure errors without bail', function () {
            $validator = Validator::make(
                ['name' => 'Neo'],
                [
                    'name' => [
                        function ($attribute, $value, $fail) {
                            $fail('first');
                            $fail('second');
                        },
                    ],
                ]
            );

            expect(count($validator->errors()['name']))->toBe(2);
        });

        it('stops after closure failure when bail is enabled', function () {
            $called = false;

            $validator = Validator::make(
                ['name' => 'Neo'],
                [
                    'name' => [
                        'bail',
                        function ($attribute, $value, $fail) {
                            $fail('failed');
                        },
                        function ($attribute, $value, $fail) use (&$called) {
                            $called = true;
                        },
                    ],
                ]
            );

            $validator->errors();

            expect($called)->toBeFalse();
        });

        it('passes all data to extension callbacks', function () {
            $received = [];

            Validator::extend(
                'matches_name',
                function ($attribute, $value, $params, $data) use (&$received) {
                    $received = [$attribute, $value, $params, $data];

                    return true;
                }
            );

            Validator::make(
                ['name' => 'Neo', 'role' => 'admin'],
                ['name' => 'matches_name:admin']
            )->passes();

            expect($received[0])->toBe('name');
            expect($received[1])->toBe('Neo');
            expect($received[2])->toBe(['admin']);
            expect($received[3]['role'])->toBe('admin');
        });
    });

    describe('Extensions', function () {

        it('supports custom extension rules', function () {
            Validator::extend(
                'even',
                fn ($attribute, $value) => is_int($value) && $value % 2 === 0
            );

            expect(
                Validator::make(
                    ['number' => 4],
                    ['number' => 'even']
                )->passes()
            )->toBeTrue();
        });

        it('rejects values through extension rules', function () {
            Validator::extend(
                'even',
                fn ($attribute, $value) => is_int($value) && $value % 2 === 0
            );

            expect(
                Validator::make(
                    ['number' => 3],
                    ['number' => 'even'],
                    ['even' => 'must be even']
                )->fails()
            )->toBeTrue();
        });

        it('uses extension messages', function () {
            Validator::extend(
                'unique_test',
                fn () => false,
                ':attribute is already used'
            );

            $errors = Validator::make(
                ['email' => 'neo@example.com'],
                ['email' => 'unique_test']
            )->errors();

            expect($errors['email'][0])->toBe('email is already used');
        });

        it('allows messages to be overridden by validator messages', function () {
            Validator::extend(
                'unique_test',
                fn () => false,
                'extension message'
            );

            $errors = Validator::make(
                ['email' => 'neo@example.com'],
                ['email' => 'unique_test'],
                ['unique_test' => 'custom message']
            )->errors();

            expect($errors['email'][0])->toBe('custom message');
        });

        it('flushes all extensions', function () {
            Validator::extend('temporary', fn () => true);

            Validator::flushExtensions();

            $thrown = false;

            try {
                Validator::make(
                    ['value' => 'test'],
                    ['value' => 'temporary']
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });

        it('rejects unknown rules', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'unknown_rule']
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });
    });

    describe('Wildcards', function () {

        it('validates every element with a wildcard rule', function () {
            expect(
                Validator::make(
                    [
                        'tags' => ['php', 'neo', 'framework'],
                    ],
                    [
                        'tags.*' => 'string',
                    ]
                )->passes()
            )->toBeTrue();
        });

        it('rejects invalid wildcard elements', function () {
            $validator = Validator::make(
                [
                    'tags' => ['php', 123, false],
                ],
                [
                    'tags.*' => 'string',
                ],
                [
                    'string' => ':attribute must be a string',
                ]
            );

            $errors = $validator->errors();

            expect(array_key_exists('tags.1', $errors))->toBeTrue();
            expect(array_key_exists('tags.2', $errors))->toBeTrue();
        });

        it('validates nested wildcard paths', function () {
            expect(
                Validator::make(
                    [
                        'users' => [
                            ['name' => 'Neo'],
                            ['name' => 'Trinity'],
                        ],
                    ],
                    [
                        'users.*.name' => 'required|string',
                    ]
                )->passes()
            )->toBeTrue();
        });

        it('returns errors for nested wildcard paths', function () {
            $validator = Validator::make(
                [
                    'users' => [
                        ['name' => 'Neo'],
                        ['name' => 123],
                    ],
                ],
                [
                    'users.*.name' => 'string',
                ],
                [
                    'string' => 'invalid :attribute',
                ]
            );

            $errors = $validator->errors();

            expect(array_key_exists('users.1.name', $errors))->toBeTrue();
        });

        it('does not expand wildcard when there are no matching elements', function () {
            expect(
                Validator::make(
                    ['tags' => []],
                    ['tags.*' => 'required']
                )->passes()
            )->toBeTrue();
        });

        it('supports wildcard-specific messages', function () {
            $validator = Validator::make(
                ['tags' => [123]],
                ['tags.*' => 'string'],
                [
                    'tags.*.string' => 'invalid tag :attribute',
                    'string' => 'generic string error',
                ]
            );

            expect($validator->errors()['tags.0'][0])->toBe('invalid tag tags.0');
        });
    });

    describe('Messages and attributes', function () {

        it('uses field-specific messages before generic messages', function () {
            $errors = Validator::make(
                ['email' => 'invalid'],
                ['email' => 'email'],
                [
                    'email.email' => 'field-specific',
                    'email' => 'generic',
                ]
            )->errors();

            expect($errors['email'][0])->toBe('field-specific');
        });

        it('uses generic messages when field-specific messages are absent', function () {
            $errors = Validator::make(
                ['email' => 'invalid'],
                ['email' => 'email'],
                ['email' => 'generic']
            )->errors();

            expect($errors['email'][0])->toBe('generic');
        });

        it('uses readable attribute names', function () {
            $errors = Validator::make(
                ['email' => 'invalid'],
                ['email' => 'email'],
                ['email' => 'Поле :attribute неверно'],
                ['email' => 'почта']
            )->errors();

            expect($errors['email'][0])->toBe('Поле почта неверно');
        });

        it('converts underscores to spaces in default attribute names', function () {
            $errors = Validator::make(
                ['first_name' => 123],
                ['first_name' => 'string'],
                ['string' => 'Поле :attribute неверно']
            )->errors();

            expect($errors['first_name'][0])->toBe('Поле first name неверно');
        });

        it('replaces numeric path segments with wildcards for message lookup', function () {
            $errors = Validator::make(
                ['users' => [['email' => 'invalid']]],
                ['users.*.email' => 'email'],
                [
                    'users.*.email.email' => 'invalid email',
                ]
            )->errors();

            expect($errors['users.0.email'][0])->toBe('invalid email');
        });

        it('replaces rule parameters in messages', function () {
            $errors = Validator::make(
                ['name' => 'Neo'],
                ['name' => 'min:5'],
                [
                    'min' => [
                        'numeric' => 'numeric minimum :min',
                        'string' => 'minimum :min characters for :attribute',
                        'array' => 'array minimum :min',
                    ],
                ]
            )->errors();

            expect($errors['name'][0])->toBe('minimum 5 characters for name');
        });

        it('replaces between parameters in messages', function () {
            $errors = Validator::make(
                ['age' => 10],
                ['age' => 'numeric|between:18,30'],
                [
                    'between' => [
                        'numeric' => 'between :min and :max',
                        'string' => 'string',
                        'array' => 'array',
                    ],
                ]
            )->errors();

            expect($errors['age'][0])->toBe('between 18 and 30');
        });

        it('replaces other field names in same messages', function () {
            $errors = Validator::make(
                ['password' => 'one', 'confirmation' => 'two'],
                ['password' => 'same:confirmation'],
                ['same' => ':attribute must match :other'],
                [
                    'password' => 'пароль',
                    'confirmation' => 'подтверждение',
                ]
            )->errors();

            expect($errors['password'][0])->toBe('пароль must match подтверждение');
        });
    });

    describe('Validated data', function () {

        it('returns only fields with validation rules', function () {
            $result = Validator::make(
                [
                    'name' => 'Neo',
                    'email' => 'neo@example.com',
                    'admin' => true,
                ],
                [
                    'name' => 'required|string',
                    'email' => 'required|email',
                ]
            )->validate();

            expect($result)->toBe([
                'name' => 'Neo',
                'email' => 'neo@example.com',
            ]);
        });

        it('returns nested fields using dot notation', function () {
            $result = Validator::make(
                [
                    'user' => [
                        'name' => 'Neo',
                        'email' => 'neo@example.com',
                        'role' => 'admin',
                    ],
                ],
                [
                    'user.name' => 'required|string',
                    'user.email' => 'required|email',
                ]
            )->validate();

            expect($result)->toBe([
                'user' => [
                    'name' => 'Neo',
                    'email' => 'neo@example.com',
                ],
            ]);
        });

        it('returns only expanded wildcard fields', function () {
            $result = Validator::make(
                [
                    'tags' => ['php', 'neo'],
                    'ignored' => true,
                ],
                [
                    'tags.*' => 'string',
                ]
            )->validate();

            expect($result)->toBe([
                'tags' => ['php', 'neo'],
            ]);
        });

        it('does not add missing fields to validated data', function () {
            $result = Validator::make(
                [],
                [
                    'name' => 'sometimes|string',
                ]
            )->validate();

            expect($result)->toBe([]);
        });

        it('returns an empty array when there are no rules', function () {
            $result = Validator::make(
                ['name' => 'Neo'],
                []
            )->validate();

            expect($result)->toBe([]);
        });

        it('throws ValidationException when validate fails', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['email' => 'invalid'],
                    ['email' => 'email'],
                    ['email' => 'invalid email']
                )->validate();
            } catch (ValidationException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });

        it('validated() behaves like validate()', function () {
            $validator = Validator::make(
                ['name' => 'Neo', 'ignored' => true],
                ['name' => 'required|string']
            );

            expect($validator->validated())->toBe([
                'name' => 'Neo',
            ]);
        });
    });

    describe('Rule parsing', function () {

        it('supports pipe-separated rules', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'required|string|min:3']
                )->passes()
            )->toBeTrue();
        });

        it('supports an array of rules', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => ['required', 'string', 'min:3']]
                )->passes()
            )->toBeTrue();
        });

        it('supports closures inside rule arrays', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    [
                        'name' => [
                            'required',
                            fn ($attribute, $value, $fail) => $value === 'Neo'
                                || $fail('invalid'),
                        ],
                    ]
                )->passes()
            )->toBeTrue();
        });

        it('ignores empty rule strings', function () {
            expect(
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => 'required||string|']
                )->passes()
            )->toBeTrue();
        });

        it('rejects invalid rule definitions', function () {
            $thrown = false;

            try {
                Validator::make(
                    ['name' => 'Neo'],
                    ['name' => ['required', 123]]
                )->errors();
            } catch (InvalidArgumentException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });

        it('caches parsed rules and validation results', function () {
            $calls = 0;

            $validator = Validator::make(
                ['name' => 'Neo'],
                [
                    'name' => [
                        function ($attribute, $value, $fail) use (&$calls) {
                            $calls++;
                        },
                    ],
                ]
            );

            $validator->errors();
            $validator->errors();
            $validator->passes();
            $validator->fails();

            expect($calls)->toBe(1);
        });
    });
});
