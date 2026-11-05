<?php

declare(strict_types=1);

require_once __DIR__ . '/TestHelpers.php';

use Neo\Cache\Contracts\PrunableContract;
use Neo\Cache\Stores\ArrayStore;

test('ArrayStore: serialize=true отдаёт копию объекта, правки снаружи не попадают в кэш', function () {
    $store = cache_array(serialize: true);
    $object = new stdClass();
    $object->n = 1;

    $store->put('o', $object);
    $object->n = 99;

    $first = $store->get('o');
    expect($first->n)->toBe(1);

    $first->n = 50;
    expect($store->get('o')->n)->toBe(1);
});

test('ArrayStore: serialize=false хранит объект по ссылке', function () {
    $store = cache_array(serialize: false);
    $object = new stdClass();

    $store->put('o', $object);

    expect($store->get('o'))->toBe($object);
});

test('ArrayStore: serialize=false не требует сериализуемости значений', function () {
    $store = cache_array(serialize: false);
    $closure = fn () => 'x';

    expect($store->put('c', $closure))->toBeTrue();
    expect($store->get('c'))->toBe($closure);
});

test('ArrayStore: реализует PrunableContract', function () {
    expect(new ArrayStore())->toBeInstanceOf(PrunableContract::class);
});

test('ArrayStore: prune удаляет только просроченные записи и считает их', function () {
    $clock = cache_clock();
    $store = cache_array($clock);

    $store->put('short', 1, 10);
    $store->put('long', 2, 100);
    $store->put('forever', 3);

    $clock->time += 50;

    expect($store->prune())->toBe(1);
    expect($store->has('short'))->toBeFalse();
    expect($store->get('long'))->toBe(2);
    expect($store->get('forever'))->toBe(3);
    expect($store->prune())->toBe(0);
});

test('ArrayStore: экземпляры независимы друг от друга', function () {
    $a = cache_array();
    $b = cache_array();

    $a->put('k', 1);

    expect($b->has('k'))->toBeFalse();
});