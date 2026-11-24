<?php

declare(strict_types=1);

use Neo\Information\Structures\Arr;
use Neo\Information\Structures\Str;

test('Str converts case styles', function () {
    expect(Str::snake('UserProfile'))->toBe('user_profile')
        ->and(Str::camel('user_profile'))->toBe('userProfile')
        ->and(Str::studly('user-profile'))->toBe('UserProfile')
        ->and(Str::kebab('UserProfile'))->toBe('user-profile')
        ->and(Str::basename('App\\Models\\User'))->toBe('User');
});

test('Str pluralizes and singularizes simple words', function () {
    expect(Str::plural('post'))->toBe('posts')
        ->and(Str::plural('category'))->toBe('categories')
        ->and(Str::plural('box'))->toBe('boxes')
        ->and(Str::singular('posts'))->toBe('post')
        ->and(Str::singular('categories'))->toBe('category')
        ->and(Str::singular('boxes'))->toBe('box');
});

test('Str::is matches wildcard patterns', function () {
    expect(Str::is('admin/*', 'admin/users/1'))->toBeTrue()
        ->and(Str::is('admin/*', 'public/users'))->toBeFalse()
        ->and(Str::is(['a', 'b*'], 'bar'))->toBeTrue()
        ->and(Str::is('a.b', 'aXb'))->toBeFalse();
});

test('Str start, finish, before and after', function () {
    expect(Str::start('path', '/'))->toBe('/path')
        ->and(Str::start('//path', '/'))->toBe('/path')
        ->and(Str::finish('dir', '/'))->toBe('dir/')
        ->and(Str::before('a@b', '@'))->toBe('a')
        ->and(Str::after('a@b', '@'))->toBe('b')
        ->and(Str::afterLast('a.b.c', '.'))->toBe('c');
});

test('Str::random is alphanumeric and has requested length', function () {
    expect(strlen(Str::random(40)))->toBe(40)
        ->and(Str::random(40))->toMatch('/^[A-Za-z0-9]{40}$/');
});

test('Arr reads and writes with dot notation', function () {
    $data = ['user' => ['name' => 'Neo']];

    Arr::set($data, 'user.age', 30);
    Arr::set($data, 'meta.tags.0', 'a');

    expect(Arr::get($data, 'user.name'))->toBe('Neo')
        ->and(Arr::get($data, 'user.age'))->toBe(30)
        ->and(Arr::get($data, 'meta.tags.0'))->toBe('a')
        ->and(Arr::get($data, 'missing.key', 'x'))->toBe('x')
        ->and(Arr::has($data, 'user.name'))->toBeTrue()
        ->and(Arr::has($data, ['user.name', 'user.zzz']))->toBeFalse();
});

test('Arr forget, only, except, pull', function () {
    $data = ['a' => 1, 'b' => ['c' => 2, 'd' => 3]];

    expect(Arr::only($data, ['a']))->toBe(['a' => 1])
        ->and(Arr::except($data, ['a']))->toBe(['b' => ['c' => 2, 'd' => 3]])
        ->and(Arr::except($data, 'b.c'))->toBe(['a' => 1, 'b' => ['d' => 3]])
        ->and(Arr::pull($data, 'b.d'))->toBe(3)
        ->and($data)->toBe(['a' => 1, 'b' => ['c' => 2]]);
});

test('Arr dot and undot are inverse', function () {
    $nested = ['a' => ['b' => 1, 'c' => ['d' => 2]], 'e' => 3];
    $flat = Arr::dot($nested);

    expect($flat)->toBe(['a.b' => 1, 'a.c.d' => 2, 'e' => 3])
        ->and(Arr::undot($flat))->toBe($nested);
});

test('Arr helpers: first, last, wrap, flatten, pluck, isAssoc', function () {
    expect(Arr::first([1, 2, 3], fn ($v) => $v > 1))->toBe(2)
        ->and(Arr::last([1, 2, 3]))->toBe(3)
        ->and(Arr::wrap('a'))->toBe(['a'])
        ->and(Arr::wrap(null))->toBe([])
        ->and(Arr::flatten([1, [2, [3]]]))->toBe([1, 2, 3])
        ->and(Arr::pluck([['id' => 1], ['id' => 2]], 'id'))->toBe([1, 2])
        ->and(Arr::isAssoc(['a' => 1]))->toBeTrue()
        ->and(Arr::isAssoc([1, 2]))->toBeFalse();
});
