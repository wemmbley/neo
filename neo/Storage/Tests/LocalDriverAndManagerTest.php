<?php

declare(strict_types=1);

use Neo\Storage\Contracts\DiskContract;
use Neo\Storage\Domain\Disk;
use Neo\Storage\Drivers\LocalDriver;
use Neo\Storage\Drivers\MemoryDriver;
use Neo\Storage\Enums\Visibility;
use Neo\Storage\Exceptions\DiskNotConfiguredException;
use Neo\Storage\Exceptions\FileAlreadyExistsException;
use Neo\Storage\Exceptions\FileNotFoundException;
use Neo\Storage\Managers\StorageManager;
use Neo\Storage\Transfers\StorageTransfer;

require_once 'TestHelpers.php';

/** Права файла в виде "0644". */
function storage_mode(string $path): string
{
    clearstatcache(true, $path);

    return substr(sprintf('%o', fileperms($path)), -4);
}

// ------------------------------------------------------------------ LocalDriver

test('local: root создаётся автоматически', function () {
    $root = storage_tmp_dir() . '/not/yet/created';
    new LocalDriver($root);

    expect(is_dir($root))->toBeTrue();
});

test('local: файлы действительно лежат внутри root', function () {
    $root = null;
    $disk = storage_manager([], $root)->disk('local');
    $disk->put('a/b.txt', 'hello');

    expect(file_get_contents($root . '/a/b.txt'))->toBe('hello');
});

test('local: права по умолчанию: serve=false => 0600, serve=true => 0644', function () {
    $root = null;
    storage_manager(['serve' => false], $root)->disk('local')->put('p.txt', 'x');
    expect(storage_mode($root . '/p.txt'))->toBe('0600');

    $root2 = null;
    storage_manager(['serve' => true], $root2)->disk('local')->put('p.txt', 'x');
    expect(storage_mode($root2 . '/p.txt'))->toBe('0644');
})->skip(!storage_posix_perms(), 'Windows: нет POSIX-прав');

test('local: явная visibility перекрывает умолчание, setVisibility меняет права', function () {
    $root = null;
    $disk = storage_manager(['serve' => false], $root)->disk('local');

    $disk->put('pub.txt', 'x', ['visibility' => 'public']);
    expect(storage_mode($root . '/pub.txt'))->toBe('0644');

    $disk->setVisibility('pub.txt', 'private');
    expect(storage_mode($root . '/pub.txt'))->toBe('0600');
    expect($disk->getVisibility('pub.txt'))->toBe('private');
})->skip(!storage_posix_perms(), 'Windows: нет POSIX-прав');

test('local: visibility в конфиге диска задаёт умолчание', function () {
    $root = null;
    storage_manager(['visibility' => 'public', 'serve' => false], $root)->disk('local')->put('x.txt', 'x');

    expect(storage_mode($root . '/x.txt'))->toBe('0644');
})->skip(!storage_posix_perms(), 'Windows: нет POSIX-прав');

test('local: после записи не остаётся временных файлов', function () {
    $root = null;
    $disk = storage_manager([], $root)->disk('local');

    $disk->put('a.txt', '1');
    $disk->put('a.txt', '2');
    $disk->copy('a.txt', 'b.txt');
    $in = fopen('php://temp', 'r+b');
    fwrite($in, 'z');
    rewind($in);
    $disk->putStream('c.txt', $in);

    $leftovers = glob($root . '/.*.tmp') ?: [];
    expect($leftovers)->toHaveCount(0);
});

test('local: временные файлы не видны в листингах', function () {
    $root = null;
    $disk = storage_manager([], $root)->disk('local');
    $disk->put('real.txt', '1');
    file_put_contents($root . '/.0123456789abcdef.tmp', 'half-written');

    expect($disk->files())->toBe(['real.txt']);
});

test('local: неудачная запись (родитель - файл) оставляет данные нетронутыми и не мусорит', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $disk->put('file.txt', 'original');

    expect(fn () => $disk->put('file.txt/child.txt', 'x'))->toThrow(\Neo\Storage\Exceptions\StorageException::class);
    expect($disk->get('file.txt'))->toBe('original');
    expect(glob($root . '/.*.tmp') ?: [])->toHaveCount(0);
});

test('local: неудачный overwrite=false не оставляет временных файлов', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $disk->put('a.txt', 'orig');

    try {
        $disk->put('a.txt', 'new', ['overwrite' => false]);
    } catch (FileAlreadyExistsException) {
    }

    expect(glob($root . '/.*.tmp') ?: [])->toHaveCount(0);
    expect($disk->get('a.txt'))->toBe('orig');
});

test('local: широкий набор имён файлов (юникод, пробелы, точки)', function () {
    $disk = storage_manager(['throw' => true])->disk('local');

    foreach (['файл.txt', 'with space.txt', '.hidden', 'a.b.c.tar.gz', 'ünï/çödé.txt', '日本語/ファイル.txt'] as $name) {
        $disk->put($name, $name);
        expect($disk->get($name))->toBe($name);
    }
});

// ------------------------------------------------------------------ StorageManager

test('manager: disk() без имени возвращает диск по умолчанию, диски кешируются', function () {
    $manager = storage_manager();

    expect($manager->getDefaultDriver())->toBe('local');
    expect($manager->disk())->toBeInstanceOf(DiskContract::class);
    expect($manager->disk())->toBe($manager->disk('local'));
    expect($manager->disk('memory'))->not->toBe($manager->disk('local'));
});

test('manager: неизвестный диск, драйвер или конфиг => DiskNotConfiguredException', function () {
    $manager = new StorageManager(new StorageTransfer([
        'default' => 'nope',
        'disks' => [
            'noroot' => ['driver' => 'local'],
            'nodriver' => ['root' => '/tmp'],
            'weird' => ['driver' => 'ftp-of-doom'],
        ],
    ]));

    expect(fn () => $manager->disk())->toThrow(DiskNotConfiguredException::class);
    expect(fn () => $manager->disk('noroot'))->toThrow(DiskNotConfiguredException::class);
    expect(fn () => $manager->disk('nodriver'))->toThrow(DiskNotConfiguredException::class);
    expect(fn () => $manager->disk('weird'))->toThrow(DiskNotConfiguredException::class);
    expect(fn () => $manager->build(['root' => '/tmp']))->toThrow(DiskNotConfiguredException::class);
});

test('manager: build() создаёт диск на лету и не регистрирует его', function () {
    $manager = storage_manager();
    $root = storage_tmp_dir() . '/onthefly';
    $disk = $manager->build(['driver' => 'local', 'root' => $root, 'throw' => true]);

    $disk->put('x.txt', 'x');

    expect(file_get_contents($root . '/x.txt'))->toBe('x');
    expect(fn () => $manager->disk('on-the-fly'))->toThrow(DiskNotConfiguredException::class);
});

test('manager: extend() регистрирует свой драйвер', function () {
    $manager = storage_manager();
    $received = null;

    $manager->extend('custom', function (array $config) use (&$received) {
        $received = $config;

        return new MemoryDriver();
    });

    $disk = $manager->build(['driver' => 'custom', 'throw' => true, 'marker' => 42]);
    $disk->put('a.txt', 'custom!');

    expect($received['marker'])->toBe(42);
    expect($disk->get('a.txt'))->toBe('custom!');
});

test('manager: фабрика может вернуть готовый Disk', function () {
    $manager = storage_manager();
    $ready = new Disk(new MemoryDriver(), 'ready', true);

    $manager->extend('ready', fn (array $c) => $ready);

    expect($manager->build(['driver' => 'ready']))->toBe($ready);
});

test('manager: fake() подменяет диск in-memory копией, ошибки на фейке бросаются', function () {
    $root = null;
    $manager = storage_manager(['throw' => false], $root);

    $fake = $manager->fake('local');
    $fake->put('a.txt', 'in memory');

    expect($manager->disk('local'))->toBe($fake);
    expect(file_exists($root . '/a.txt'))->toBeFalse(); // на реальный диск ничего не попало
    expect(fn () => $fake->copy('a.txt', 'a.txt'))->toThrow(FileAlreadyExistsException::class);
    expect(fn () => $fake->get('ghost'))->toThrow(FileNotFoundException::class);
});

test('manager: fake() без имени подменяет диск по умолчанию', function () {
    $manager = storage_manager();
    $fake = $manager->fake();

    expect($manager->disk())->toBe($fake);
    expect($manager->disk('memory'))->not->toBe($fake);
});

test('manager: link() создаёт симлинки, не трогает существующее, force пересоздаёт', function () {
    $base = storage_tmp_dir();
    $target = $base . '/storage/app';
    $other = $base . '/storage/other';
    mkdir($target, 0777, true);
    mkdir($other, 0777, true);
    $link = $base . '/public-storage';

    $manager = storage_manager([], $r, null, [$link => $target]);

    expect($manager->link())->toBe([$link => $target]);
    expect(is_link($link))->toBeTrue();
    expect(storage_path(readlink($link)))->toBe($target);

    expect($manager->link())->toBe([]); // уже есть

    // другая цель + force
    $manager2 = storage_manager([], $r2, null, [$link => $other]);
    expect($manager2->link())->toBe([]);
    expect($manager2->link(true))->toBe([$link => $other]);
    expect(storage_path(readlink($link)))->toBe($other);
});

test('manager: link() не удаляет реальные папки/файлы, даже с force', function () {
    $base = storage_tmp_dir();
    $target = $base . '/target';
    mkdir($target);
    $real = $base . '/real-dir';
    mkdir($real);
    file_put_contents($real . '/important.txt', 'data');

    $manager = storage_manager([], $r, null, [$real => $target]);

    expect($manager->link(true))->toBe([]);
    expect(is_link($real))->toBeFalse();
    expect(file_get_contents($real . '/important.txt'))->toBe('data');
});

test('manager: link() на несуществующую цель => FileNotFoundException', function () {
    $base = storage_tmp_dir();
    $manager = storage_manager([], $r, null, [$base . '/lnk' => $base . '/missing']);

    expect(fn () => $manager->link())->toThrow(FileNotFoundException::class);
});

test('Visibility::resolve', function () {
    expect(Visibility::resolve(null, Visibility::Private))->toBe(Visibility::Private);
    expect(Visibility::resolve('public', Visibility::Private))->toBe(Visibility::Public);
    expect(Visibility::resolve(Visibility::Public, Visibility::Private))->toBe(Visibility::Public);
    expect(fn () => Visibility::resolve('nope', Visibility::Private))
        ->toThrow(\Neo\Storage\Exceptions\UnsupportedOperationException::class);
});