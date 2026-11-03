<?php

declare(strict_types=1);

namespace Neo\Transfer\Tests;

use LogicException;
use Neo\Transfer\BasicTransfer;
use Neo\Validator\Rule;
use Neo\Validator\ValidationException;

/*
|--------------------------------------------------------------------------
| Test DTO fixtures
|--------------------------------------------------------------------------
*/

class BasicTransferUserFixture extends BasicTransfer
{
    public string $name;
    public int $age;
    public bool $active = true;

    public function validateName(Rule $rule): bool
    {
        return $this->name !== '';
    }

    protected function validateAge(Rule $rule): bool
    {
        return $this->age >= 18;
    }
}

class BasicTransferSnakeFixture extends BasicTransfer
{
    public string $dependencyInjection;
}

class BasicTransferNestedFixture extends BasicTransfer
{
    public string $value;

    public function validateValue(Rule $rule): bool
    {
        return $this->value !== '';
    }
}

class BasicTransferParentFixture extends BasicTransfer
{
    public BasicTransferNestedFixture $child;
}

class BasicTransferExtraAllowedFixture extends BasicTransfer
{
    protected const bool ALLOW_EXTRA = true;

    public string $name;
}

class BasicTransferReadonlyFixture extends BasicTransfer
{
    public readonly string $name;
}

class BasicTransferStaticFixture extends BasicTransfer
{
    public string $name;

    public static string $ignored = 'default';
}

class BasicTransferRuleFixture extends BasicTransfer
{
    public string $name;

    public function validateName(Rule $rule): Rule
    {
        return $rule;
    }
}

class BasicTransferInvalidReturnFixture extends BasicTransfer
{
    public string $name;

    public function validateName(Rule $rule): null
    {
        return null;
    }
}

class BasicTransferInvalidValidatorFixture extends BasicTransfer
{
    public string $name;

    public function validateUnknown(Rule $rule): bool
    {
        return true;
    }
}

class BasicTransferExceptionFixture extends BasicTransfer
{
    public string $name;

    public function validateName(Rule $rule): bool
    {
        throw new ValidationException([
            'name' => ['Custom validation error'],
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

describe('BasicTransfer', function () {

    describe('Hydration', function () {

        it('maps array values to public properties', function () {
            $transfer = new BasicTransferUserFixture([
                'name' => 'John',
                'age' => 25,
            ]);

            expect($transfer->name)->toBe('John');
            expect($transfer->age)->toBe(25);
        });

        it('preserves default property values when keys are missing', function () {
            $transfer = new BasicTransferUserFixture([
                'name' => 'John',
                'age' => 25,
            ]);

            expect($transfer->active)->toBeTrue();
        });

        it('maps snake_case keys to camelCase properties', function () {
            $transfer = new BasicTransferSnakeFixture([
                'dependency_injection' => 'container',
            ]);

            expect($transfer->dependencyInjection)->toBe('container');
        });

        it('accepts the exact property key', function () {
            $transfer = new BasicTransferSnakeFixture([
                'dependencyInjection' => 'exact',
            ]);

            expect($transfer->dependencyInjection)->toBe('exact');
        });

        it('ignores static properties during hydration', function () {
            $transfer = new BasicTransferStaticFixture([
                'name' => 'John',
            ]);

            expect($transfer->name)->toBe('John');
            expect(BasicTransferStaticFixture::$ignored)->toBe('default');
        });

        it('initializes readonly properties', function () {
            $transfer = new BasicTransferReadonlyFixture([
                'name' => 'John',
            ]);

            expect($transfer->name)->toBe('John');
        });

    });

    describe('Validation', function () {

        it('validates properties through public validators', function () {
            $transfer = new BasicTransferUserFixture([
                'name' => 'John',
                'age' => 25,
            ]);

            expect($transfer->name)->toBe('John');
        });

        it('validates properties through protected validators', function () {
            $transfer = new BasicTransferUserFixture([
                'name' => 'John',
                'age' => 25,
            ]);

            expect($transfer->age)->toBe(25);
        });

        it('throws ValidationException when a validator returns false', function () {
            try {
                new BasicTransferUserFixture([
                    'name' => '',
                    'age' => 25,
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

        it('accepts a Rule returned by a validator', function () {
            $transfer = new BasicTransferRuleFixture([
                'name' => 'John',
            ]);

            expect($transfer->name)->toBe('John');
        });

        it('throws LogicException when a validator returns an invalid value', function () {
            try {
                new BasicTransferInvalidReturnFixture([
                    'name' => 'John',
                ]);

                expect(false)->toBeTrue();
            } catch (LogicException $exception) {
                expect($exception)->toBeInstanceOf(LogicException::class);
            }
        });

        it('throws LogicException when validator references an unknown property', function () {
            try {
                new BasicTransferInvalidValidatorFixture([
                    'name' => 'John',
                ]);

                expect(false)->toBeTrue();
            } catch (LogicException $exception) {
                expect($exception)->toBeInstanceOf(LogicException::class);
            }
        });

        it('allows validators to throw ValidationException directly', function () {
            try {
                new BasicTransferExceptionFixture([
                    'name' => 'John',
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

    });

    describe('Type validation', function () {

        it('throws ValidationException when a property receives an incompatible type', function () {
            try {
                new BasicTransferUserFixture([
                    'name' => 'John',
                    'age' => '25',
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

        it('does not execute a validator for a property with an invalid type', function () {
            try {
                new BasicTransferUserFixture([
                    'name' => 'John',
                    'age' => 'invalid',
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

    });

    describe('Required properties', function () {

        it('throws ValidationException when a required property is missing', function () {
            try {
                new BasicTransferUserFixture([
                    'name' => 'John',
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

        it('does not require properties that have default values', function () {
            $transfer = new BasicTransferUserFixture([
                'name' => 'John',
                'age' => 25,
            ]);

            expect($transfer->active)->toBeTrue();
        });

    });

    describe('Extra keys', function () {

        it('rejects unknown keys by default', function () {
            try {
                new BasicTransferUserFixture([
                    'name' => 'John',
                    'age' => 25,
                    'unknown' => true,
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

        it('allows unknown keys when ALLOW_EXTRA is enabled', function () {
            $transfer = new BasicTransferExtraAllowedFixture([
                'name' => 'John',
                'unknown' => true,
            ]);

            expect($transfer->name)->toBe('John');
        });

    });

    describe('Nested transfers', function () {

        it('automatically creates a nested DTO from an array', function () {
            $transfer = new BasicTransferParentFixture([
                'child' => [
                    'value' => 'nested',
                ],
            ]);

            expect($transfer->child)->toBeInstanceOf(
                BasicTransferNestedFixture::class
            );

            expect($transfer->child->value)->toBe('nested');
        });

        it('validates nested DTO properties', function () {
            try {
                new BasicTransferParentFixture([
                    'child' => [
                        'value' => '',
                    ],
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

        it('rejects an incompatible value for a nested DTO property', function () {
            try {
                new BasicTransferParentFixture([
                    'child' => 'invalid',
                ]);

                expect(false)->toBeTrue();
            } catch (ValidationException $exception) {
                expect($exception)->toBeInstanceOf(ValidationException::class);
            }
        });

    });

});
