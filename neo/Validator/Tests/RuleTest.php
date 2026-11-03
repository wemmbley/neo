<?php

declare(strict_types=1);

namespace Neo\Validator\Tests;

use Neo\Validator\Rule;
use Neo\Validator\ValidationException;

interface RuleFixtureInterface {}

class RuleFixtureClass implements RuleFixtureInterface {}

class RuleFixtureChild extends RuleFixtureClass {}

enum RuleFixtureEnum
{
    case First;
}

describe('Rule', function () {

    describe('Construction', function () {

        it('creates a rule through of()', function () {
            $rule = Rule::of('value', 'name');

            expect($rule)->toBeInstanceOf(Rule::class);
            expect($rule->passes())->toBeTrue();
        });

        it('uses value as the default path', function () {
            $errors = Rule::of(123)->string()->errors();

            expect(array_key_exists('value', $errors))->toBeTrue();
        });

        it('returns the original value from validate()', function () {
            $value = ['name' => 'Neo'];

            expect(Rule::of($value)->validate())->toBe($value);
        });

        it('returns the same rule instance when chaining', function () {
            $rule = Rule::of('Neo');

            expect($rule->string())->toBe($rule);
        });
    });

    describe('Types', function () {

        it('validates array', function () {
            expect(Rule::of([])->array()->passes())->toBeTrue();
            expect(Rule::of('array')->array()->fails())->toBeTrue();
        });

        it('validates string', function () {
            expect(Rule::of('Neo')->string()->passes())->toBeTrue();
            expect(Rule::of(123)->string()->fails())->toBeTrue();
        });

        it('validates bool', function () {
            expect(Rule::of(true)->bool()->passes())->toBeTrue();
            expect(Rule::of(false)->bool()->passes())->toBeTrue();
            expect(Rule::of(1)->bool()->fails())->toBeTrue();
        });

        it('validates float', function () {
            expect(Rule::of(1.5)->float()->passes())->toBeTrue();
            expect(Rule::of(1)->float()->fails())->toBeTrue();
        });

        it('validates int', function () {
            expect(Rule::of(10)->int()->passes())->toBeTrue();
            expect(Rule::of(10.0)->int()->fails())->toBeTrue();
        });

        it('validates object', function () {
            expect(Rule::of(new \stdClass())->object()->passes())->toBeTrue();
            expect(Rule::of([])->object()->fails())->toBeTrue();
        });

        it('validates callable', function () {
            expect(Rule::of(fn () => true)->callable()->passes())->toBeTrue();
            expect(Rule::of('not_callable')->callable()->fails())->toBeTrue();
        });

        it('validates resource', function () {
            $resource = fopen('php://memory', 'r');

            expect(Rule::of($resource)->resource()->passes())->toBeTrue();
            expect(Rule::of('resource')->resource()->fails())->toBeTrue();

            fclose($resource);
        });

        it('validates numeric', function () {
            expect(Rule::of(123)->numeric()->passes())->toBeTrue();
            expect(Rule::of('123.45')->numeric()->passes())->toBeTrue();
            expect(Rule::of('abc')->numeric()->fails())->toBeTrue();
        });

        it('validates scalar', function () {
            expect(Rule::of('text')->scalar()->passes())->toBeTrue();
            expect(Rule::of(123)->scalar()->passes())->toBeTrue();
            expect(Rule::of(true)->scalar()->passes())->toBeTrue();
            expect(Rule::of([])->scalar()->fails())->toBeTrue();
        });

        it('supports null type', function () {
            expect(Rule::of(null)->type('null')->passes())->toBeTrue();
            expect(Rule::of('null')->type('null')->fails())->toBeTrue();
        });

        it('supports mixed type', function () {
            expect(Rule::of(null)->type('mixed')->passes())->toBeTrue();
            expect(Rule::of([])->type('mixed')->passes())->toBeTrue();
            expect(Rule::of(123)->type('mixed')->passes())->toBeTrue();
        });

        it('supports custom class types', function () {
            expect(
                Rule::of(new RuleFixtureClass())
                    ->type(RuleFixtureClass::class)
                    ->passes()
            )->toBeTrue();

            expect(
                Rule::of(new \stdClass())
                    ->type(RuleFixtureClass::class)
                    ->fails()
            )->toBeTrue();
        });

        it('supports interface types', function () {
            expect(
                Rule::of(new RuleFixtureClass())
                    ->type(RuleFixtureInterface::class)
                    ->passes()
            )->toBeTrue();
        });

        it('supports multiple types as a union', function () {
            expect(
                Rule::of('Neo')
                    ->string()
                    ->int()
                    ->passes()
            )->toBeTrue();

            expect(
                Rule::of(123)
                    ->string()
                    ->int()
                    ->passes()
            )->toBeTrue();

            expect(
                Rule::of([])
                    ->string()
                    ->int()
                    ->fails()
            )->toBeTrue();
        });

        it('does not add duplicate types', function () {
            expect(
                Rule::of('Neo')
                    ->string()
                    ->string()
                    ->passes()
            )->toBeTrue();
        });

        it('stops validation when the type does not match', function () {
            $called = false;

            $rule = Rule::of(123)
                ->string()
                ->custom(function () use (&$called) {
                    $called = true;
                });

            $rule->errors();

            expect($called)->toBeFalse();
        });

        it('allows null when nullable is enabled', function () {
            expect(
                Rule::of(null)
                    ->string()
                    ->nullable()
                    ->passes()
            )->toBeTrue();
        });

        it('does not allow null without nullable', function () {
            expect(
                Rule::of(null)
                    ->string()
                    ->passes()
            )->toBeFalse();
        });

        it('skips checks when nullable value is null', function () {
            $called = false;

            Rule::of(null)
                ->nullable()
                ->custom(function () use (&$called) {
                    $called = true;
                })
                ->validate();

            expect($called)->toBeFalse();
        });
    });

    describe('General checks', function () {

        it('validates strict inclusion with in()', function () {
            expect(Rule::of('admin')->in(['admin', 'user'])->passes())->toBeTrue();
            expect(Rule::of('guest')->in(['admin', 'user'])->fails())->toBeTrue();
        });

        it('uses strict comparison in in()', function () {
            expect(Rule::of(1)->in(['1'])->fails())->toBeTrue();
            expect(Rule::of(1)->in([1])->passes())->toBeTrue();
        });

        it('formats allowed values in error messages', function () {
            $errors = Rule::of('guest', 'role')
                ->in(['admin', 'user', 10, []])
                ->errors();

            expect(array_key_exists('role', $errors))->toBeTrue();
            expect(count($errors['role']))->toBe(1);
        });

        it('accepts non-empty strings', function () {
            expect(Rule::of('Neo')->notEmpty()->passes())->toBeTrue();
        });

        it('rejects empty strings', function () {
            expect(Rule::of('')->notEmpty()->fails())->toBeTrue();
        });

        it('accepts non-empty arrays', function () {
            expect(Rule::of([1])->notEmpty()->passes())->toBeTrue();
        });

        it('rejects empty arrays', function () {
            expect(Rule::of([])->notEmpty()->fails())->toBeTrue();
        });

        it('accepts zero and false as non-empty values', function () {
            expect(Rule::of(0)->notEmpty()->passes())->toBeTrue();
            expect(Rule::of(false)->notEmpty()->passes())->toBeTrue();
        });

        it('supports custom validation', function () {
            expect(
                Rule::of('Neo')
                    ->custom(fn ($attribute, $value, $fail) => $value === 'Neo' ?: $fail('invalid'))
                    ->passes()
            )->toBeTrue();
        });

        it('adds custom validation errors', function () {
            $errors = Rule::of('invalid', 'username')
                ->custom(function ($attribute, $value, $fail) {
                    $fail('Поле :attribute недопустимо');
                })
                ->errors();

            expect(array_key_exists('username', $errors))->toBeTrue();
            expect($errors['username'][0])->toBe('Поле username недопустимо');
        });

        it('supports multiple custom errors', function () {
            $errors = Rule::of('invalid', 'name')
                ->custom(function ($attribute, $value, $fail) {
                    $fail('Первая ошибка');
                    $fail('Вторая ошибка');
                })
                ->errors();

            expect(count($errors['name']))->toBe(2);
        });

        it('stops after the first failed check', function () {
            $secondCalled = false;

            Rule::of('')
                ->notEmpty()
                ->custom(function () use (&$secondCalled) {
                    $secondCalled = true;
                })
                ->errors();

            expect($secondCalled)->toBeFalse();
        });
    });

    describe('Size', function () {

        it('validates minimum numeric value', function () {
            expect(Rule::of(10)->min(10)->passes())->toBeTrue();
            expect(Rule::of(9)->min(10)->fails())->toBeTrue();
        });

        it('validates maximum numeric value', function () {
            expect(Rule::of(10)->max(10)->passes())->toBeTrue();
            expect(Rule::of(11)->max(10)->fails())->toBeTrue();
        });

        it('validates exact numeric size', function () {
            expect(Rule::of(10)->size(10)->passes())->toBeTrue();
            expect(Rule::of(9)->size(10)->fails())->toBeTrue();
        });

        it('validates minimum string length', function () {
            expect(Rule::of('Neo')->min(3)->passes())->toBeTrue();
            expect(Rule::of('Ne')->min(3)->fails())->toBeTrue();
        });

        it('validates maximum string length', function () {
            expect(Rule::of('Neo')->max(3)->passes())->toBeTrue();
            expect(Rule::of('Neo4')->max(3)->fails())->toBeTrue();
        });

        it('validates exact string length', function () {
            expect(Rule::of('Neo')->size(3)->passes())->toBeTrue();
            expect(Rule::of('Neo4')->size(3)->fails())->toBeTrue();
        });

        it('validates minimum array count', function () {
            expect(Rule::of([1, 2])->min(2)->passes())->toBeTrue();
            expect(Rule::of([1])->min(2)->fails())->toBeTrue();
        });

        it('validates maximum array count', function () {
            expect(Rule::of([1, 2])->max(2)->passes())->toBeTrue();
            expect(Rule::of([1, 2, 3])->max(2)->fails())->toBeTrue();
        });

        it('validates exact array count', function () {
            expect(Rule::of([1, 2])->size(2)->passes())->toBeTrue();
            expect(Rule::of([1])->size(2)->fails())->toBeTrue();
        });

        it('uses multibyte string length', function () {
            expect(Rule::of('Привет')->size(6)->passes())->toBeTrue();
            expect(Rule::of('Привет')->size(5)->fails())->toBeTrue();
        });

        it('rejects values without a defined size', function () {
            expect(Rule::of(new \stdClass())->min(1)->fails())->toBeTrue();
        });

        it('does not reject unsupported size types when size checks are not reached', function () {
            $called = false;

            Rule::of(new \stdClass())
                ->custom(function () use (&$called) {
                    $called = true;
                })
                ->errors();

            expect($called)->toBeTrue();
        });
    });

    describe('Strings', function () {

        it('validates regex patterns', function () {
            expect(
                Rule::of('neo_123')
                    ->string()
                    ->regex('/^[a-z_0-9]+$/')
                    ->passes()
            )->toBeTrue();
        });

        it('rejects strings that do not match regex', function () {
            expect(
                Rule::of('Neo!')
                    ->string()
                    ->regex('/^[a-z]+$/')
                    ->fails()
            )->toBeTrue();
        });

        it('rejects non-string values in regex', function () {
            expect(
                Rule::of(123)
                    ->regex('/^[0-9]+$/')
                    ->fails()
            )->toBeTrue();
        });
    });

    describe('Classes', function () {

        it('accepts an existing class string', function () {
            expect(
                Rule::of(RuleFixtureClass::class)
                    ->classString()
                    ->passes()
            )->toBeTrue();
        });

        it('accepts an existing interface string', function () {
            expect(
                Rule::of(RuleFixtureInterface::class)
                    ->classString()
                    ->passes()
            )->toBeTrue();
        });

        it('accepts an existing enum string', function () {
            expect(
                Rule::of(RuleFixtureEnum::class)
                    ->classString()
                    ->passes()
            )->toBeTrue();
        });

        it('rejects an unknown class string', function () {
            expect(
                Rule::of('Neo\\Unknown\\MissingClass')
                    ->classString()
                    ->fails()
            )->toBeTrue();
        });

        it('validates object instances with instanceOf()', function () {
            expect(
                Rule::of(new RuleFixtureClass())
                    ->instanceOf(RuleFixtureClass::class)
                    ->passes()
            )->toBeTrue();
        });

        it('validates inherited classes with instanceOf()', function () {
            expect(
                Rule::of(new RuleFixtureChild())
                    ->instanceOf(RuleFixtureClass::class)
                    ->passes()
            )->toBeTrue();
        });

        it('validates implemented interfaces with instanceOf()', function () {
            expect(
                Rule::of(new RuleFixtureClass())
                    ->instanceOf(RuleFixtureInterface::class)
                    ->passes()
            )->toBeTrue();
        });

        it('validates class names with instanceOf()', function () {
            expect(
                Rule::of(RuleFixtureChild::class)
                    ->instanceOf(RuleFixtureClass::class)
                    ->passes()
            )->toBeTrue();
        });

        it('rejects unrelated objects with instanceOf()', function () {
            expect(
                Rule::of(new \stdClass())
                    ->instanceOf(RuleFixtureClass::class)
                    ->fails()
            )->toBeTrue();
        });

        it('rejects scalar values with instanceOf()', function () {
            expect(
                Rule::of('text')
                    ->instanceOf(RuleFixtureClass::class)
                    ->fails()
            )->toBeTrue();
        });
    });

    describe('Filesystem', function () {

        it('accepts an existing directory', function () {
            expect(
                Rule::of(sys_get_temp_dir())
                    ->directory()
                    ->passes()
            )->toBeTrue();
        });

        it('rejects a missing directory', function () {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neo-missing-directory';

            expect(
                Rule::of($path)
                    ->directory()
                    ->fails()
            )->toBeTrue();
        });

        it('accepts an existing file', function () {
            $path = tempnam(sys_get_temp_dir(), 'neo-rule-');

            expect(
                Rule::of($path)
                    ->file()
                    ->passes()
            )->toBeTrue();

            unlink($path);
        });

        it('rejects a missing file', function () {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neo-missing-file.txt';

            expect(
                Rule::of($path)
                    ->file()
                    ->fails()
            )->toBeTrue();
        });

        it('accepts a writable existing directory', function () {
            expect(
                Rule::of(sys_get_temp_dir())
                    ->writable()
                    ->passes()
            )->toBeTrue();
        });

        it('accepts a not-yet-existing path when its parent is writable', function () {
            $path = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR
                . 'neo-future'
                . DIRECTORY_SEPARATOR
                . 'cache';

            expect(
                Rule::of($path)
                    ->writable()
                    ->passes()
            )->toBeTrue();
        });

        it('rejects non-string filesystem paths', function () {
            expect(Rule::of(123)->directory()->fails())->toBeTrue();
            expect(Rule::of(123)->file()->fails())->toBeTrue();
        });
    });

    describe('Arrays', function () {

        it('implicitly requires array for associative()', function () {
            expect(Rule::of(['name' => 'Neo'])->associative()->passes())->toBeTrue();
            expect(Rule::of('Neo')->associative()->fails())->toBeTrue();
        });

        it('accepts an empty associative array', function () {
            expect(Rule::of([])->associative()->passes())->toBeTrue();
        });

        it('rejects a sequential array as associative', function () {
            expect(Rule::of([1, 2, 3])->associative()->fails())->toBeTrue();
        });

        it('accepts associative keys', function () {
            expect(Rule::of(['name' => 'Neo'])->associative()->passes())->toBeTrue();
        });

        it('implicitly requires array for list()', function () {
            expect(Rule::of([1, 2])->list()->passes())->toBeTrue();
            expect(Rule::of('Neo')->list()->fails())->toBeTrue();
        });

        it('accepts an empty list', function () {
            expect(Rule::of([])->list()->passes())->toBeTrue();
        });

        it('rejects associative arrays as lists', function () {
            expect(Rule::of(['name' => 'Neo'])->list()->fails())->toBeTrue();
        });

        it('validates array keys by type', function () {
            expect(
                Rule::of(['name' => 'Neo', 'email' => 'neo@example.com'])
                    ->keys('string')
                    ->passes()
            )->toBeTrue();
        });

        it('collects errors for all invalid keys', function () {
            $errors = Rule::of([
                'name' => 'Neo',
                10 => 'value',
                20 => 'value',
            ], 'data')
                ->keys('string')
                ->errors();

            expect(count($errors))->toBe(2);
            expect(array_key_exists('data.10 (ключ)', $errors))->toBeTrue();
            expect(array_key_exists('data.20 (ключ)', $errors))->toBeTrue();
        });

        it('validates array keys with a closure', function () {
            expect(
                Rule::of(['name' => 'Neo'])
                    ->keys(fn (Rule $rule) => $rule->string()->notEmpty())
                    ->passes()
            )->toBeTrue();
        });

        it('validates array values by type', function () {
            expect(
                Rule::of(['Neo', 'PHP'])
                    ->values('string')
                    ->passes()
            )->toBeTrue();
        });

        it('collects errors for all invalid values', function () {
            $errors = Rule::of([
                'first' => 123,
                'second' => false,
            ], 'items')
                ->values('string')
                ->errors();

            expect(count($errors))->toBe(2);
            expect(array_key_exists('items.first', $errors))->toBeTrue();
            expect(array_key_exists('items.second', $errors))->toBeTrue();
        });

        it('validates array values with a closure', function () {
            expect(
                Rule::of(['Neo', 'Framework'])
                    ->values(fn (Rule $rule) => $rule->string()->min(3))
                    ->passes()
            )->toBeTrue();
        });

        it('validates nested array values', function () {
            expect(
                Rule::of([
                    'user' => ['name' => 'Neo'],
                ])
                    ->values(fn (Rule $rule) => $rule->array())
                    ->passes()
            )->toBeTrue();
        });
    });

    describe('Shape', function () {

        it('validates a valid array shape', function () {
            $data = [
                'name' => 'Neo',
                'age' => 25,
            ];

            expect(
                Rule::of($data, 'user')
                    ->shape([
                        'name' => 'string',
                        'age' => 'int',
                    ])
                    ->passes()
            )->toBeTrue();
        });

        it('rejects missing required fields', function () {
            $errors = Rule::of([
                'name' => 'Neo',
            ], 'user')
                ->shape([
                    'name' => 'string',
                    'age' => 'int',
                ])
                ->errors();

            expect(array_key_exists('user.age', $errors))->toBeTrue();
        });

        it('allows missing optional fields', function () {
            expect(
                Rule::of([
                    'name' => 'Neo',
                ])
                    ->shape([
                        'name' => 'string',
                        'age?' => 'int',
                    ])
                    ->passes()
            )->toBeTrue();
        });

        it('validates present optional fields', function () {
            expect(
                Rule::of([
                    'name' => 'Neo',
                    'age' => 25,
                ])
                    ->shape([
                        'name' => 'string',
                        'age?' => 'int',
                    ])
                    ->passes()
            )->toBeTrue();
        });

        it('rejects invalid optional fields when present', function () {
            expect(
                Rule::of([
                    'name' => 'Neo',
                    'age' => '25',
                ])
                    ->shape([
                        'name' => 'string',
                        'age?' => 'int',
                    ])
                    ->fails()
            )->toBeTrue();
        });

        it('rejects unknown keys by default', function () {
            $errors = Rule::of([
                'name' => 'Neo',
                'extra' => true,
            ], 'user')
                ->shape([
                    'name' => 'string',
                ])
                ->errors();

            expect(array_key_exists('user.extra', $errors))->toBeTrue();
        });

        it('allows unknown keys when allowExtra is true', function () {
            expect(
                Rule::of([
                    'name' => 'Neo',
                    'extra' => true,
                ])
                    ->shape([
                        'name' => 'string',
                    ], true)
                    ->passes()
            )->toBeTrue();
        });

        it('validates fields using closures', function () {
            expect(
                Rule::of([
                    'name' => 'Neo',
                ])
                    ->shape([
                        'name' => fn (Rule $rule) => $rule->string()->min(3),
                    ])
                    ->passes()
            )->toBeTrue();
        });

        it('collects errors from multiple shape fields', function () {
            $errors = Rule::of([
                'name' => 123,
                'age' => 'invalid',
                'extra' => true,
            ], 'user')
                ->shape([
                    'name' => 'string',
                    'age' => 'int',
                    'email' => 'string',
                ])
                ->errors();

            expect(array_key_exists('user.name', $errors))->toBeTrue();
            expect(array_key_exists('user.age', $errors))->toBeTrue();
            expect(array_key_exists('user.email', $errors))->toBeTrue();
            expect(array_key_exists('user.extra', $errors))->toBeTrue();
        });

        it('supports nested shape validation', function () {
            expect(
                Rule::of([
                    'user' => [
                        'name' => 'Neo',
                        'age' => 25,
                    ],
                ], 'config')
                    ->shape([
                        'user' => fn (Rule $rule) => $rule->array()->shape([
                            'name' => 'string',
                            'age' => 'int',
                        ]),
                    ])
                    ->passes()
            )->toBeTrue();
        });

        it('returns nested error paths', function () {
            $errors = Rule::of([
                'user' => [
                    'name' => 123,
                ],
            ], 'config')
                ->shape([
                    'user' => fn (Rule $rule) => $rule->array()->shape([
                        'name' => 'string',
                    ]),
                ])
                ->errors();

            expect(array_key_exists('config.user.name', $errors))->toBeTrue();
        });

        it('implicitly requires array for shape()', function () {
            expect(
                Rule::of('not-array')
                    ->shape(['name' => 'string'])
                    ->fails()
            )->toBeTrue();
        });
    });

    describe('Errors and validation', function () {

        it('returns an empty array when validation succeeds', function () {
            expect(
                Rule::of('Neo')->string()->errors()
            )->toBe([]);
        });

        it('returns errors indexed by the configured path', function () {
            $errors = Rule::of(123, 'user.name')
                ->string()
                ->errors();

            expect(array_key_exists('user.name', $errors))->toBeTrue();
        });

        it('returns false from fails() when validation fails', function () {
            expect(Rule::of(123)->string()->fails())->toBeTrue();
        });

        it('returns true from passes() when validation succeeds', function () {
            expect(Rule::of('Neo')->string()->passes())->toBeTrue();
        });

        it('returns false from passes() when validation fails', function () {
            expect(Rule::of(123)->string()->passes())->toBeFalse();
        });

        it('returns the original value from validate()', function () {
            $value = ['name' => 'Neo'];

            expect(
                Rule::of($value)->array()->validate()
            )->toBe($value);
        });

        it('throws ValidationException when validation fails', function () {
            $thrown = false;

            try {
                Rule::of(123)->string()->validate();
            } catch (ValidationException) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
        });

        it('resets errors between validation runs', function () {
            $rule = Rule::of('', 'name')->notEmpty();

            expect($rule->errors())->toBe($rule->errors());
        });

        it('re-evaluates custom checks on every run', function () {
            $calls = 0;

            $rule = Rule::of('Neo')
                ->custom(function () use (&$calls) {
                    $calls++;
                });

            $rule->errors();
            $rule->errors();

            expect($calls)->toBe(2);
        });

        it('supports repeated calls to passes() and fails()', function () {
            $rule = Rule::of('Neo')->string();

            expect($rule->passes())->toBeTrue();
            expect($rule->fails())->toBeFalse();
            expect($rule->passes())->toBeTrue();
        });
    });
});
