<?php

declare(strict_types=1);

require_once 'TestHelpers.php';

use Neo\Cache\Exceptions\CacheException;

/*
 * Общее поведение: одинаково для ArrayStore и FileStore (memory, local, local без throw).
 */

foreach (cache_store_kinds() as $kind) {
    // ── Базовые операции ───────────────────────────────────

    test("[$kind] put/get возвращают значения разных типов", function () use ($kind) {
        $store = cache_store($kind);

        expect($store->put('str', 'hello'))->toBeTrue();
        expect($store->put('int', 42))->toBeTrue();
        expect($store->put('float', 1.5))->toBeTrue();
        expect($store->put('bool', false))->toBeTrue();
        expect($store->put('arr', ['a' => 1, 'b' => [2, 3]]))->toBeTrue();

        expect($store->get('str'))->toBe('hello');
        expect($store->get('int'))->toBe(42);
        expect($store->get('float'))->toBe(1.5);
        expect($store->get('bool'))->toBe(false);
        expect($store->get('arr'))->toBe(['a' => 1, 'b' => [2, 3]]);
    });

    test("[$kind] объекты возвращаются как равные копии", function () use ($kind) {
        $store = cache_store($kind);
        $object = new stdClass();
        $object->name = 'neo';

        $store->put('obj', $object);

        expect($store->get('obj'))->toEqual($object);
    });

    test("[$kind] отсутствующий ключ даёт default, в том числе замыкание", function () use ($kind) {
        $store = cache_store($kind);

        expect($store->get('missing'))->toBeNull();
        expect($store->get('missing', 'fallback'))->toBe('fallback');
        expect($store->get('missing', fn() => 'lazy'))->toBe('lazy');
    });

    test("[$kind] put перезаписывает значение", function () use ($kind) {
        $store = cache_store($kind);

        $store->put('k', 1);
        $store->put('k', 2);

        expect($store->get('k'))->toBe(2);
    });

    test("[$kind] has для одного ключа и для массива", function () use ($kind) {
        $store = cache_store($kind);
        $store->put('a', 1);
        $store->put('b', 2);

        expect($store->has('a'))->toBeTrue();
        expect($store->has('nope'))->toBeFalse();
        expect($store->has(['a', 'b']))->toBeTrue();
        expect($store->has(['a', 'nope']))->toBeFalse();
    });

    test("[$kind] null кэшируется как значение", function () use ($kind) {
        $store = cache_store($kind);

        $store->put('n', null);

        expect($store->has('n'))->toBeTrue();
        expect($store->get('n', 'default'))->toBeNull();
    });

    test("[$kind] ключи со спецсимволами и юникодом", function () use ($kind) {
        $store = cache_store($kind);

        foreach (['user:1/profile', 'a b', '../x', 'ключ', 'k.k-k_k'] as $key) {
            $store->put($key, $key);
        }

        foreach (['user:1/profile', 'a b', '../x', 'ключ', 'k.k-k_k'] as $key) {
            expect($store->get($key))->toBe($key);
        }
    });

    test("[$kind] пустой ключ бросает CacheException", function () use ($kind) {
        $store = cache_store($kind);

        expect(fn() => $store->get(''))->toThrow(CacheException::class);
        expect(fn() => $store->put('', 1))->toThrow(CacheException::class);
        expect(fn() => $store->forget(''))->toThrow(CacheException::class);
        expect(fn() => $store->add('', 1))->toThrow(CacheException::class);
    });

    // ── forget / many / putMany / forever ──────────────────

    test("[$kind] forget удаляет один и несколько ключей", function () use ($kind) {
        $store = cache_store($kind);
        $store->putMany(['a' => 1, 'b' => 2, 'c' => 3]);

        expect($store->forget('a'))->toBeTrue();
        expect($store->has('a'))->toBeFalse();

        expect($store->forget(['b', 'c']))->toBeTrue();
        expect($store->has(['b']))->toBeFalse();
        expect($store->has(['c']))->toBeFalse();
    });

    test("[$kind] forget несуществующего ключа возвращает true", function () use ($kind) {
        expect(cache_store($kind)->forget('ghost'))->toBeTrue();
    });

    test("[$kind] many возвращает значения и null для отсутствующих", function () use ($kind) {
        $store = cache_store($kind);
        $store->put('a', 1);

        expect($store->many(['a', 'b']))->toBe(['a' => 1, 'b' => null]);
    });

    test("[$kind] putMany сохраняет всё и учитывает ttl", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        expect($store->putMany(['a' => 1, 'b' => 2], 10))->toBeTrue();
        expect($store->many(['a', 'b']))->toBe(['a' => 1, 'b' => 2]);

        $clock->time += 10;

        expect($store->has('a'))->toBeFalse();
        expect($store->has('b'))->toBeFalse();
    });

    test("[$kind] forever не истекает", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->forever('k', 'v');
        $clock->time += 10 * 365 * 86400;

        expect($store->get('k'))->toBe('v');
    });

    test("[$kind] put без ttl не истекает", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('k', 'v');
        $clock->time += 10 * 365 * 86400;

        expect($store->get('k'))->toBe('v');
    });

    // ── TTL ────────────────────────────────────────────────

    test("[$kind] ttl в секундах: живёт до момента истечения, в момент истечения уже нет", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('k', 'v', 60);

        $clock->time += 59;
        expect($store->get('k'))->toBe('v');

        $clock->time += 1;
        expect($store->has('k'))->toBeFalse();
        expect($store->get('k', 'gone'))->toBe('gone');
    });

    test("[$kind] ttl как DateInterval", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('k', 'v', new DateInterval('PT1M'));

        $clock->time += 59;
        expect($store->has('k'))->toBeTrue();

        $clock->time += 1;
        expect($store->has('k'))->toBeFalse();
    });

    test("[$kind] ttl как DateTimeInterface", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('k', 'v', new DateTimeImmutable('@' . ($clock->time + 120)));

        $clock->time += 119;
        expect($store->has('k'))->toBeTrue();

        $clock->time += 1;
        expect($store->has('k'))->toBeFalse();
    });

    test("[$kind] ttl <= 0 удаляет существующий ключ", function () use ($kind) {
        $store = cache_store($kind, cache_clock());

        $store->put('a', 1);
        expect($store->put('a', 2, 0))->toBeTrue();
        expect($store->has('a'))->toBeFalse();

        $store->put('b', 1);
        expect($store->put('b', 2, -5))->toBeTrue();
        expect($store->has('b'))->toBeFalse();
    });

    test("[$kind] дата в прошлом в качестве ttl удаляет ключ", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('k', 1);
        $store->put('k', 2, new DateTimeImmutable('@' . ($clock->time - 1)));

        expect($store->has('k'))->toBeFalse();
    });

    test("[$kind] перезапись обновляет срок жизни", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('k', 1, 10);
        $clock->time += 9;
        $store->put('k', 2, 10);
        $clock->time += 9;

        expect($store->get('k'))->toBe(2);
    });

    // ── add ────────────────────────────────────────────────

    test("[$kind] add записывает только отсутствующий ключ", function () use ($kind) {
        $store = cache_store($kind);

        expect($store->add('k', 'first'))->toBeTrue();
        expect($store->add('k', 'second'))->toBeFalse();
        expect($store->get('k'))->toBe('first');
    });

    test("[$kind] add поверх просроченного ключа срабатывает", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('k', 'old', 10);
        $clock->time += 10;

        expect($store->add('k', 'new', 60))->toBeTrue();
        expect($store->get('k'))->toBe('new');
    });

    test("[$kind] add с ttl <= 0 ничего не пишет и возвращает false", function () use ($kind) {
        $store = cache_store($kind, cache_clock());

        expect($store->add('k', 'v', 0))->toBeFalse();
        expect($store->has('k'))->toBeFalse();
    });

    // ── pull ───────────────────────────────────────────────

    test("[$kind] pull возвращает значение и удаляет ключ", function () use ($kind) {
        $store = cache_store($kind);
        $store->put('k', 'v');

        expect($store->pull('k'))->toBe('v');
        expect($store->has('k'))->toBeFalse();
    });

    test("[$kind] pull отсутствующего ключа возвращает default", function () use ($kind) {
        $store = cache_store($kind);

        expect($store->pull('k'))->toBeNull();
        expect($store->pull('k', 'd'))->toBe('d');
        expect($store->pull('k', fn() => 'lazy'))->toBe('lazy');
    });

    // ── remember ───────────────────────────────────────────

    test("[$kind] remember вычисляет значение один раз", function () use ($kind) {
        $store = cache_store($kind);
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;

            return 'computed';
        };

        expect($store->remember('k', 60, $callback))->toBe('computed');
        expect($store->remember('k', 60, $callback))->toBe('computed');
        expect($calls)->toBe(1);
    });

    test("[$kind] remember пересчитывает после истечения", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);
        $calls = 0;

        $callback = function () use (&$calls) {
            return ++$calls;
        };

        expect($store->remember('k', 10, $callback))->toBe(1);

        $clock->time += 10;

        expect($store->remember('k', 10, $callback))->toBe(2);
    });

    test("[$kind] remember кэширует null", function () use ($kind) {
        $store = cache_store($kind);
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;

            return null;
        };

        $store->remember('k', 60, $callback);
        $store->remember('k', 60, $callback);

        expect($calls)->toBe(1);
    });

    test("[$kind] rememberForever не истекает", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        expect($store->rememberForever('k', fn() => 'v'))->toBe('v');

        $clock->time += 10 * 365 * 86400;

        expect($store->get('k'))->toBe('v');
    });

    test("[$kind] исключение в callback remember не кэшируется", function () use ($kind) {
        $store = cache_store($kind);

        expect(fn() => $store->remember('k', 60, function () {
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class);

        expect($store->has('k'))->toBeFalse();
    });

    // ── increment / decrement ──────────────────────────────

    test("[$kind] increment создаёт отсутствующий ключ со значением шага", function () use ($kind) {
        $store = cache_store($kind);

        expect($store->increment('n'))->toBe(1);
        expect($store->increment('m', 5))->toBe(5);
        expect($store->get('m'))->toBe(5);
    });

    test("[$kind] increment и decrement на существующем значении", function () use ($kind) {
        $store = cache_store($kind);
        $store->put('n', 10);

        expect($store->increment('n', 5))->toBe(15);
        expect($store->decrement('n', 3))->toBe(12);
        expect($store->get('n'))->toBe(12);
    });

    test("[$kind] decrement отсутствующего ключа уходит в минус", function () use ($kind) {
        expect(cache_store($kind)->decrement('n', 4))->toBe(-4);
    });

    test("[$kind] increment работает с float", function () use ($kind) {
        $store = cache_store($kind);
        $store->put('f', 1.5);

        expect($store->increment('f', 1))->toBe(2.5);
        expect($store->increment('f', 0.5))->toBe(3.0);
    });

    test("[$kind] increment нечислового значения возвращает false и не меняет его", function () use ($kind) {
        $store = cache_store($kind);
        $store->put('s', 'abc');

        expect($store->increment('s'))->toBeFalse();
        expect($store->decrement('s'))->toBeFalse();
        expect($store->get('s'))->toBe('abc');
    });

    test("[$kind] increment сохраняет срок жизни существующего ключа", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('n', 1, 60);
        $clock->time += 30;

        expect($store->increment('n'))->toBe(2);

        $clock->time += 30;

        expect($store->has('n'))->toBeFalse();
    });

    test("[$kind] счётчик, созданный increment, живёт без срока", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->increment('n');
        $clock->time += 10 * 365 * 86400;

        expect($store->get('n'))->toBe(1);
    });

    test("[$kind] increment просроченного ключа начинает с нуля", function () use ($kind) {
        $clock = cache_clock();
        $store = cache_store($kind, $clock);

        $store->put('n', 100, 10);
        $clock->time += 10;

        expect($store->increment('n'))->toBe(1);
    });

    // ── flush ──────────────────────────────────────────────

    test("[$kind] flush очищает хранилище и оно остаётся рабочим", function () use ($kind) {
        $store = cache_store($kind);
        $store->putMany(['a' => 1, 'b' => 2, 'c' => 3]);

        expect($store->flush())->toBeTrue();

        expect($store->has('a'))->toBeFalse();
        expect($store->has('b'))->toBeFalse();
        expect($store->has('c'))->toBeFalse();

        expect($store->put('a', 'again'))->toBeTrue();
        expect($store->get('a'))->toBe('again');
    });

    test("[$kind] flush пустого хранилища не падает", function () use ($kind) {
        expect(cache_store($kind)->flush())->toBeTrue();
    });

    // ── Несериализуемые значения ───────────────────────────

    test("[$kind] замыкание в значении бросает CacheException и ничего не пишет", function () use ($kind) {
        $store = cache_store($kind);

        expect(fn() => $store->put('f', fn() => 1))->toThrow(CacheException::class);
        expect($store->has('f'))->toBeFalse();
    });
}