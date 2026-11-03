<?php

declare(strict_types=1);

/**
 * Глобальные функции для тестов в стиле Pest.
 *
 *   test('что-то работает', function () { expect(1)->toBe(1); });
 *   it('делает то-то', fn () => expect(true)->toBeTrue());
 *   describe('группа', function () { it('...', ...); });
 *   beforeEach(function () { $this->x = 1; });  // $this общий для хуков и теста
 *   it('...', function (int $a, int $b) {...})->with([[1, 2], [3, 4]]);
 */

use Neo\Testing\Expectation;
use Neo\Testing\Runner;
use Neo\Testing\Test;

if (!function_exists('test')) {
    /** Объявляет тест. Без замыкания тест считается «todo». */
    function test(string $description, ?Closure $closure = null): Test
    {
        return Runner::instance()->addTest($description, $closure);
    }
}

if (!function_exists('it')) {
    /** Как test(), но к названию добавляется «it». */
    function it(string $description, ?Closure $closure = null): Test
    {
        return Runner::instance()->addTest('it ' . $description, $closure);
    }
}

if (!function_exists('describe')) {
    /** Группирует тесты; название группы добавляется к названиям тестов. */
    function describe(string $name, Closure $body): void
    {
        Runner::instance()->describe($name, $body);
    }
}

if (!function_exists('beforeEach')) {
    /** Выполняется перед каждым тестом области (файла или группы). */
    function beforeEach(Closure $hook): void
    {
        Runner::instance()->beforeEach($hook);
    }
}

if (!function_exists('afterEach')) {
    /** Выполняется после каждого теста области, даже если тест упал. */
    function afterEach(Closure $hook): void
    {
        Runner::instance()->afterEach($hook);
    }
}

if (!function_exists('expect')) {
    /** Начинает проверку значения. */
    function expect(mixed $value): Expectation
    {
        return new Expectation($value);
    }
}
