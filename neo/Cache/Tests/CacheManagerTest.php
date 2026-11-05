<?php

declare(strict_types=1);

require_once __DIR__ . '/TestHelpers.php';

use Neo\Cache\Contracts\CacheStoreContract;
use Neo\Cache\Exceptions\CacheNotConfiguredException;
use Neo\Cache\Stores\ArrayStore;
use Neo\Cache\Stores\FileStore;

// ── Создание и выбор сторов ────────────────────────────────

test('Manager: store() без аргумента возвращает стор по умолчанию', function () {
    $manager = cache_manager([
        'array' => ['driver' => 'array'],
        'other' => ['driver' => 'array'],
    ], default: 'array');

    expect($manager->getDefaultStore())->toBe('array');
    expect($manager->store())->toBe($manager->store('array'));
    expect($manager->store())->toBeInstanceOf(ArrayStore::class);
});

test('Manager: экземпляры сторов кешируются', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);

    expect($manager->store('array'))->toBe($manager->store('array'));
});

test('Manager: разные сторы независимы', function () {
    $manager = cache_manager([
        'one' => ['driver' => 'array'],
        'two' => ['driver' => 'array'],
    ], default: 'one');

    $manager->store('one')->put('k', 1);

    expect($manager->store('two')->has('k'))->toBeFalse();
});

test('Manager: file-драйвер создаёт FileStore поверх указанного диска', function () {
    $storage = cache_storage('memory');
    $manager = cache_manager([
        'file' => ['driver' => 'file', 'disk' => 'memory', 'directory' => 'cache-x'],
    ], default: 'file', storage: $storage);

    $manager->put('k', 'v');

    expect($manager->store())->toBeInstanceOf(FileStore::class);
    expect(count($storage->disk('memory')->allFiles('cache-x')))->toBe(1);
    expect($manager->get('k'))->toBe('v');
});

test('Manager: file-драйвер без disk берёт диск по умолчанию, без directory — cache', function () {
    $storage = cache_storage('memory');
    $manager = cache_manager([
        'file' => ['driver' => 'file'],
    ], default: 'file', storage: $storage);

    $manager->put('k', 'v');

    expect(count($storage->disk()->allFiles('cache')))->toBe(1);
});

test('Manager: serialize=false передаётся в ArrayStore', function () {
    $manager = cache_manager(['ref' => ['driver' => 'array', 'serialize' => false]], default: 'ref');
    $object = new stdClass();

    $manager->put('o', $object);

    expect($manager->get('o'))->toBe($object);
});

// ── Префиксы ───────────────────────────────────────────────

test('Manager: общий префикс наследуется, префикс стора его перекрывает', function () {
    $storage = cache_storage('memory');
    $manager = cache_manager([
        'inherit' => ['driver' => 'file', 'disk' => 'memory'],
        'same' => ['driver' => 'file', 'disk' => 'memory', 'prefix' => 'neo'],
        'own' => ['driver' => 'file', 'disk' => 'memory', 'prefix' => 'custom'],
    ], default: 'inherit', prefix: 'neo', storage: $storage);

    $manager->store('inherit')->put('k', 'v');

    // тот же эффективный префикс 'neo' → та же запись
    expect($manager->store('same')->get('k'))->toBe('v');
    // другой префикс → изолировано
    expect($manager->store('own')->has('k'))->toBeFalse();
});

// ── Ошибки конфигурации ────────────────────────────────────

test('Manager: неизвестный стор бросает CacheNotConfiguredException', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);

    expect(fn () => $manager->store('ghost'))->toThrow(CacheNotConfiguredException::class);
});

test('Manager: стор по умолчанию, которого нет в stores, бросает при обращении', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']], default: 'ghost');

    expect(fn () => $manager->get('k'))->toThrow(CacheNotConfiguredException::class);
});

test('Manager: стор без driver бросает CacheNotConfiguredException', function () {
    $manager = cache_manager(['broken' => ['disk' => 'memory']], default: 'broken');

    expect(fn () => $manager->store())->toThrow(CacheNotConfiguredException::class);
});

test('Manager: неподдерживаемый драйвер бросает CacheNotConfiguredException', function () {
    $manager = cache_manager(['x' => ['driver' => 'memcached']], default: 'x');

    expect(fn () => $manager->store())->toThrow(CacheNotConfiguredException::class);
});

test('Manager: file-драйвер без StorageManager бросает CacheNotConfiguredException', function () {
    $manager = cache_manager(['file' => ['driver' => 'file']], default: 'file');

    expect(fn () => $manager->store())->toThrow(CacheNotConfiguredException::class);
});

// ── extend / build / fake / purge ──────────────────────────

test('Manager: extend добавляет драйвер и передаёт ему конфиг с префиксом', function () {
    $received = null;

    $manager = cache_manager(['mine' => ['driver' => 'custom', 'option' => 7]], default: 'mine', prefix: 'neo');
    $manager->extend('custom', function (array $config) use (&$received) {
        $received = $config;

        return cache_array(prefix: $config['prefix']);
    });

    $manager->put('k', 'v');

    expect($manager->get('k'))->toBe('v');
    expect($received['option'])->toBe(7);
    expect($received['prefix'])->toBe('neo');
});

test('Manager: extend возвращает менеджер для чейнинга', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);

    expect($manager->extend('custom', fn () => cache_array()))->toBe($manager);
});

test('Manager: драйвер, вернувший не стор, бросает CacheNotConfiguredException', function () {
    $manager = cache_manager(['bad' => ['driver' => 'custom']], default: 'bad');
    $manager->extend('custom', fn () => new stdClass());

    expect(fn () => $manager->store())->toThrow(CacheNotConfiguredException::class);
});

test('Manager: кастомный драйвер может переопределить встроенный', function () {
    $custom = cache_array();
    $manager = cache_manager(['a' => ['driver' => 'array']], default: 'a');
    $manager->extend('array', fn () => $custom);

    expect($manager->store())->toBe($custom);
});

test('Manager: build создаёт стор на лету и не регистрирует его', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);

    $built = $manager->build(['driver' => 'array']);

    expect($built)->toBeInstanceOf(CacheStoreContract::class);
    expect($built)->not->toBe($manager->store('array'));
    expect(fn () => $manager->store('(inline)'))->toThrow(CacheNotConfiguredException::class);
});

test('Manager: build без driver бросает CacheNotConfiguredException', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);

    expect(fn () => $manager->build([]))->toThrow(CacheNotConfiguredException::class);
});

test('Manager: fake подменяет стор in-memory копией и не трогает диск', function () {
    $storage = cache_storage('memory');
    $manager = cache_manager([
        'file' => ['driver' => 'file', 'disk' => 'memory'],
    ], default: 'file', storage: $storage);

    $fake = $manager->fake();

    $manager->put('k', 'v');

    expect($fake)->toBeInstanceOf(ArrayStore::class);
    expect($manager->store())->toBe($fake);
    expect($manager->get('k'))->toBe('v');
    expect($storage->disk()->allFiles('cache'))->toBe([]);
});

test('Manager: purge пересоздаёт стор при следующем обращении', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);

    $first = $manager->store('array');
    $first->put('k', 'v');

    $manager->purge('array');

    $second = $manager->store('array');

    expect($second)->not->toBe($first);
    expect($second->has('k'))->toBeFalse();
});

test('Manager: purge без аргумента сбрасывает стор по умолчанию', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);

    $first = $manager->store();
    $manager->purge();

    expect($manager->store())->not->toBe($first);
});

// ── Проксирование в стор по умолчанию ──────────────────────

test('Manager: все методы контракта проксируются в стор по умолчанию', function () {
    $manager = cache_manager(['array' => ['driver' => 'array']]);
    $store = $manager->store();

    expect($manager->put('a', 1))->toBeTrue();
    expect($store->get('a'))->toBe(1);
    expect($manager->get('a'))->toBe(1);
    expect($manager->get('ghost', 'd'))->toBe('d');
    expect($manager->has('a'))->toBeTrue();
    expect($manager->has(['a', 'ghost']))->toBeFalse();

    expect($manager->putMany(['b' => 2, 'c' => 3]))->toBeTrue();
    expect($manager->many(['a', 'b', 'c', 'ghost']))->toBe(['a' => 1, 'b' => 2, 'c' => 3, 'ghost' => null]);

    expect($manager->forever('f', 'x'))->toBeTrue();
    expect($store->get('f'))->toBe('x');

    expect($manager->add('a', 'nope'))->toBeFalse();
    expect($manager->add('new', 'yes'))->toBeTrue();

    expect($manager->pull('new'))->toBe('yes');
    expect($store->has('new'))->toBeFalse();

    expect($manager->remember('r', 60, fn () => 'computed'))->toBe('computed');
    expect($manager->rememberForever('rf', fn () => 'forever'))->toBe('forever');

    expect($manager->increment('n', 2))->toBe(2);
    expect($manager->decrement('n'))->toBe(1);

    expect($manager->forget('a'))->toBeTrue();
    expect($store->has('a'))->toBeFalse();
    expect($manager->forget(['b', 'c']))->toBeTrue();

    expect($manager->flush())->toBeTrue();
    expect($store->has('f'))->toBeFalse();
});

test('Manager: проксирование идёт именно в default, а не в первый стор', function () {
    $manager = cache_manager([
        'first' => ['driver' => 'array'],
        'second' => ['driver' => 'array'],
    ], default: 'second');

    $manager->put('k', 'v');

    expect($manager->store('second')->get('k'))->toBe('v');
    expect($manager->store('first')->has('k'))->toBeFalse();
});

test('Manager: реализует CacheStoreContract', function () {
    expect(cache_manager(['array' => ['driver' => 'array']]))->toBeInstanceOf(CacheStoreContract::class);
});