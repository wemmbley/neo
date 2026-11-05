<?php

declare(strict_types=1);

require_once __DIR__ . '/TestHelpers.php';

use Neo\Cache\Contracts\PrunableContract;
use Neo\Cache\Stores\FileStore;

foreach (['memory', 'local'] as $diskKind) {
    test("FileStore [$diskKind]: запись лежит в <directory>/ab/cd/<sha256>.cache", function () use ($diskKind) {
        $disk = cache_disk($diskKind);
        $store = cache_file($disk);

        $store->put('user:1', ['id' => 1]);

        $files = $disk->allFiles('cache');

        expect(count($files))->toBe(1);
        expect(preg_match('~(^|/)[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}\.cache$~', $files[0]))->toBe(1);
    });

    test("FileStore [$diskKind]: данные видны другому экземпляру на том же диске", function () use ($diskKind) {
        $disk = cache_disk($diskKind);

        cache_file($disk)->put('k', 'v');

        expect(cache_file($disk)->get('k'))->toBe('v');
    });

    test("FileStore [$diskKind]: разные префиксы изолируют одинаковые ключи", function () use ($diskKind) {
        $disk = cache_disk($diskKind);
        $a = cache_file($disk, prefix: 'a:');
        $b = cache_file($disk, prefix: 'b:');

        $a->put('k', 'from-a');
        $b->put('k', 'from-b');

        expect($a->get('k'))->toBe('from-a');
        expect($b->get('k'))->toBe('from-b');

        $a->forget('k');

        expect($a->has('k'))->toBeFalse();
        expect($b->get('k'))->toBe('from-b');
    });

    test("FileStore [$diskKind]: повреждённый файл считается промахом и удаляется", function () use ($diskKind) {
        $disk = cache_disk($diskKind);
        $store = cache_file($disk);

        $store->put('k', 'v');
        $file = $disk->allFiles('cache')[0];
        $disk->put($file, 'это не сериализованные данные', ['overwrite' => true]);

        expect($store->get('k', 'miss'))->toBe('miss');
        expect($disk->allFiles('cache'))->toBe([]);
    });

    test("FileStore [$diskKind]: валидная сериализация неправильной формы тоже промах", function () use ($diskKind) {
        $disk = cache_disk($diskKind);
        $store = cache_file($disk);

        $store->put('k', 'v');
        $file = $disk->allFiles('cache')[0];
        $disk->put($file, serialize(['x' => 1]), ['overwrite' => true]);

        expect($store->has('k'))->toBeFalse();
        expect($disk->allFiles('cache'))->toBe([]);
    });

    test("FileStore [$diskKind]: просроченный файл удаляется при чтении", function () use ($diskKind) {
        $clock = cache_clock();
        $disk = cache_disk($diskKind);
        $store = cache_file($disk, $clock);

        $store->put('k', 'v', 10);
        $clock->time += 10;

        expect($store->get('k'))->toBeNull();
        expect($disk->allFiles('cache'))->toBe([]);
    });

    test("FileStore [$diskKind]: просроченный файл не мешает add", function () use ($diskKind) {
        $clock = cache_clock();
        $store = cache_file(cache_disk($diskKind), $clock);

        $store->put('k', 'old', 10);
        $clock->time += 11;

        expect($store->add('k', 'new', 60))->toBeTrue();
        expect($store->get('k'))->toBe('new');
    });

    test("FileStore [$diskKind]: add не затирает живую запись", function () use ($diskKind) {
        $store = cache_file(cache_disk($diskKind));

        $store->put('k', 'alive');

        expect($store->add('k', 'intruder'))->toBeFalse();
        expect($store->get('k'))->toBe('alive');
    });

    test("FileStore [$diskKind]: prune удаляет просроченные файлы и возвращает их число", function () use ($diskKind) {
        $clock = cache_clock();
        $disk = cache_disk($diskKind);
        $store = cache_file($disk, $clock);

        $store->put('short-1', 1, 10);
        $store->put('short-2', 2, 20);
        $store->put('long', 3, 1000);
        $store->put('forever', 4);

        $clock->time += 100;

        expect($store->prune())->toBe(2);
        expect(count($disk->allFiles('cache')))->toBe(2);
        expect($store->get('long'))->toBe(3);
        expect($store->get('forever'))->toBe(4);
        expect($store->prune())->toBe(0);
    });

    test("FileStore [$diskKind]: prune убирает повреждённые файлы", function () use ($diskKind) {
        $disk = cache_disk($diskKind);
        $store = cache_file($disk);

        $store->put('k', 'v');
        $file = $disk->allFiles('cache')[0];
        $disk->put($file, 'мусор', ['overwrite' => true]);

        expect($store->prune())->toBe(1);
        expect($disk->allFiles('cache'))->toBe([]);
    });

    test("FileStore [$diskKind]: prune на пустом хранилище возвращает 0", function () use ($diskKind) {
        expect(cache_file(cache_disk($diskKind))->prune())->toBe(0);
    });

    test("FileStore [$diskKind]: flush трогает только свою директорию", function () use ($diskKind) {
        $disk = cache_disk($diskKind);
        $store = cache_file($disk);

        $disk->put('other/keep.txt', 'important');
        $store->putMany(['a' => 1, 'b' => 2]);

        expect($store->flush())->toBeTrue();

        expect($store->has('a'))->toBeFalse();
        expect($disk->exists('other/keep.txt'))->toBeTrue();
        expect($disk->get('other/keep.txt'))->toBe('important');
    });

    test("FileStore [$diskKind]: сторы в разных директориях flush-ятся независимо", function () use ($diskKind) {
        $disk = cache_disk($diskKind);
        $one = cache_file($disk, directory: 'cache-one');
        $two = cache_file($disk, directory: 'cache-two');

        $one->put('k', 1);
        $two->put('k', 2);

        $one->flush();

        expect($one->has('k'))->toBeFalse();
        expect($two->get('k'))->toBe(2);
    });
}

test('FileStore: реализует PrunableContract', function () {
    expect(cache_file(cache_disk()))->toBeInstanceOf(PrunableContract::class);
});

test('FileStore: корень диска в качестве директории запрещён', function () {
    $disk = cache_disk();

    expect(fn () => new FileStore($disk, ''))->toThrow(InvalidArgumentException::class);
    expect(fn () => new FileStore($disk, '/'))->toThrow(InvalidArgumentException::class);
});

test('FileStore: слеши по краям директории не влияют на раскладку', function () {
    $disk = cache_disk();

    (new FileStore($disk, '/cache/'))->put('k', 'v');

    expect(count($disk->allFiles('cache')))->toBe(1);
});