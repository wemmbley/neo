<?php

declare(strict_types=1);

use Neo\Storage\Exceptions\PathTraversalException;
use Neo\Storage\Domain\PathNormalizer;

require_once 'TestHelpers.php';

// ------------------------------------------------------------------ PathNormalizer

test('PathNormalizer: нормализует слеши, точки и дубли', function () {
    expect(PathNormalizer::normalize('/a//b/./c/'))->toBe('a/b/c');
    expect(PathNormalizer::normalize('a\\b\\c'))->toBe('a/b/c');
    expect(PathNormalizer::normalize(''))->toBe('');
    expect(PathNormalizer::normalize('/'))->toBe('');
    expect(PathNormalizer::normalize('.'))->toBe('');
});

test('PathNormalizer: join склеивает и нормализует', function () {
    expect(PathNormalizer::join('avatars/', '/me.png'))->toBe('avatars/me.png');
    expect(PathNormalizer::join('', 'file.txt'))->toBe('file.txt');
});

test('PathNormalizer: отклоняет опасные пути', function () {
    $evil = [
        '..', '../x', 'a/../../x', 'a/..', '..\\x', 'a\\..\\..\\x',
        "a\0b", "a\nb", "a\x1Fb", "a\x7Fb",
        'php://memory', 'file:///etc/passwd', 'phar://x.phar/a', 'data://text/plain;base64,AAAA',
        'C:/Windows/win.ini', 'c:\\x',
    ];

    foreach ($evil as $path) {
        expect(fn () => PathNormalizer::normalize($path))->toThrow(PathTraversalException::class);
    }
});

test('PathNormalizer: имена файлов с точками внутри разрешены', function () {
    expect(PathNormalizer::normalize('a/.hidden/file..txt'))->toBe('a/.hidden/file..txt');
    expect(PathNormalizer::normalize('a/...'))->toBe('a/...');
});

test('PathNormalizer::filename: ровно один сегмент', function () {
    expect(PathNormalizer::filename('photo.png'))->toBe('photo.png');

    foreach (['', '.', '..', 'a/b', '/a', 'a/', '..\\x', "x\0"] as $name) {
        expect(fn () => PathNormalizer::filename($name))->toThrow(PathTraversalException::class);
    }
});

// ------------------------------------------------------------------ оба драйвера

foreach (['local', 'memory'] as $kind) {
    test("[$kind] любая операция с traversal-путём бросает PathTraversalException", function () use ($kind) {
        $disk = storage_disk($kind);
        $bad = ['../x', 'a/../../x', "a\0b", 'php://memory', 'C:/x', '..\\x'];
        $disk->put('ok.txt', 'x'); // легитимный источник для copy/move

        foreach ($bad as $path) {
            expect(fn () => $disk->get($path))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->put($path, 'x'))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->exists($path))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->delete($path))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->copy($path, 'ok.txt'))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->move('ok.txt', $path))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->makeDirectory($path))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->deleteDirectory($path))->toThrow(PathTraversalException::class);
            expect(fn () => $disk->files($path))->toThrow(PathTraversalException::class);
        }
    });
}

test('traversal бросается даже при throw=false (атаку не глотаем)', function () {
    $disk = storage_manager(['throw' => false])->disk('local');

    expect(fn () => $disk->put('../evil.txt', 'x'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->delete('../evil.txt'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->copy('../a', 'b'))->toThrow(PathTraversalException::class);
});

test('traversal-попытка не создаёт файлов за пределами root', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $outside = dirname($root) . '/evil.txt';

    foreach (['../evil.txt', 'a/../../evil.txt'] as $path) {
        try {
            $disk->put($path, 'pwned');
        } catch (PathTraversalException) {
        }
    }

    expect(file_exists($outside))->toBeFalse();
});

// ------------------------------------------------------------------ symlink-escape (local)

test('local: симлинк-папка внутри root, ведущая наружу, не даёт читать/писать', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $outside = storage_tmp_dir() . '/outside';
    mkdir($outside);
    file_put_contents($outside . '/secret.txt', 'TOP SECRET');

    symlink($outside, $root . '/evil');

    expect(fn () => $disk->get('evil/secret.txt'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->exists('evil/secret.txt'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->put('evil/new.txt', 'x'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->delete('evil/secret.txt'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->makeDirectory('evil/sub'))->toThrow(PathTraversalException::class);

    expect(file_exists($outside . '/new.txt'))->toBeFalse();
    expect(file_get_contents($outside . '/secret.txt'))->toBe('TOP SECRET');
});

test('local: симлинк-файл, ведущий наружу, недоступен', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $secret = storage_tmp_dir() . '/secret.txt';
    file_put_contents($secret, 'TOP SECRET');

    symlink($secret, $root . '/link.txt');

    expect(fn () => $disk->get('link.txt'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->put('link.txt', 'overwritten'))->toThrow(PathTraversalException::class);
    expect(fn () => $disk->delete('link.txt'))->toThrow(PathTraversalException::class);
    expect(file_get_contents($secret))->toBe('TOP SECRET');
});

test('local: битый симлинк считается атакой', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');

    $target = storage_tmp_dir() . '/gone.txt';
    file_put_contents($target, 'x');
    symlink($target, $root . '/dangling');
    unlink($target); // теперь симлинк ведёт в никуда

    expect(is_link($root . '/dangling'))->toBeTrue();
    expect(fn () => $disk->get('dangling'))->toThrow(PathTraversalException::class);
});

test('local: симлинк внутри root на другое место внутри root работает', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $disk->put('real/data.txt', 'hello');
    symlink($root . '/real', $root . '/alias');

    expect($disk->get('alias/data.txt'))->toBe('hello');
});

test('local: симлинки не попадают в листинги', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $outside = storage_tmp_dir();
    $disk->put('ok.txt', '1');
    symlink($outside, $root . '/linked-dir');
    symlink($outside, $root . '/linked-file');

    expect($disk->files())->toBe(['ok.txt']);
    expect($disk->directories())->toHaveCount(0);
});

test('local: deleteDirectory удаляет симлинк, но не содержимое цели', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $outside = storage_tmp_dir() . '/precious';
    mkdir($outside);
    file_put_contents($outside . '/keep.txt', 'keep me');

    $disk->put('dir/a.txt', 'a');
    symlink($outside, $root . '/dir/shortcut');

    $disk->deleteDirectory('dir');

    expect(is_dir($root . '/dir'))->toBeFalse();
    expect(file_get_contents($outside . '/keep.txt'))->toBe('keep me');
});

test('local: корень диска удалить нельзя', function () {
    $root = null;
    $disk = storage_manager(['throw' => true], $root)->disk('local');
    $disk->put('a.txt', 'a');

    foreach (['', '/', '.', './'] as $path) {
        expect(fn () => $disk->deleteDirectory($path))->toThrow(\Neo\Storage\Exceptions\UnableToDeleteException::class);
    }

    expect(file_exists($root . '/a.txt'))->toBeTrue();
});