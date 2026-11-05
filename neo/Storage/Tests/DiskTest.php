<?php

declare(strict_types=1);

use Neo\Storage\Exceptions\FileAlreadyExistsException;
use Neo\Storage\Exceptions\FileNotFoundException;
use Neo\Storage\Exceptions\PathTraversalException;
use Neo\Storage\Exceptions\StorageException;
use Neo\Storage\Exceptions\UnableToDeleteException;
use Neo\Storage\Exceptions\UnableToReadException;
use Neo\Storage\Exceptions\UnsupportedOperationException;
use Neo\Http\Response\StorageResponse;

require_once 'TestHelpers.php';

// Одинаковое поведение обязано совпадать у ВСЕХ драйверов.
foreach (['local', 'memory'] as $kind) {
    // ------------------------------------------------------------ чтение / запись

    test("[$kind] put/get: круговая запись, перезапись, вложенные папки", function () use ($kind) {
        $disk = storage_disk($kind);
        expect($disk->put('a/b/c.txt', 'one'))->toBeTrue();
        expect($disk->get('a/b/c.txt'))->toBe('one');

        $disk->put('a/b/c.txt', 'two');
        expect($disk->get('a/b/c.txt'))->toBe('two');
        expect($disk->exists('a/b/c.txt'))->toBeTrue();
        expect($disk->missing('a/b/c.txt'))->toBeFalse();
        expect($disk->directoryExists('a/b'))->toBeTrue();
        expect($disk->directoryExists('nope'))->toBeFalse();
    });

    test("[$kind] бинарные данные записываются без искажений", function () use ($kind) {
        $disk = storage_disk($kind);
        $bin = random_bytes(4096) . "\0\r\n\xFF";
        $disk->put('bin.dat', $bin);

        expect($disk->get('bin.dat'))->toBe($bin);
        expect($disk->size('bin.dat'))->toBe(strlen($bin));
    });

    test("[$kind] overwrite=false не затирает существующий файл", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('keep.txt', 'original');

        expect(fn () => $disk->put('keep.txt', 'new', ['overwrite' => false]))
            ->toThrow(FileAlreadyExistsException::class);
        expect($disk->get('keep.txt'))->toBe('original');
    });

    test("[$kind] чтение несуществующего файла: FileNotFoundException", function () use ($kind) {
        $disk = storage_disk($kind);
        expect(fn () => $disk->get('missing.txt'))->toThrow(FileNotFoundException::class);
        expect(fn () => $disk->size('missing.txt'))->toThrow(FileNotFoundException::class);
        expect(fn () => $disk->mimeType('missing.txt'))->toThrow(FileNotFoundException::class);
        expect(fn () => $disk->readStream('missing.txt'))->toThrow(FileNotFoundException::class);
        expect($disk->exists('missing.txt'))->toBeFalse();
    });

    test("[$kind] нельзя записать файл поверх директории", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('dir/file.txt', 'x');

        expect(fn () => $disk->put('dir', 'x'))->toThrow(StorageException::class);
        expect(fn () => $disk->get('dir'))->toThrow(FileNotFoundException::class);
    });

    test("[$kind] append/prepend", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('log.txt', 'b');
        $disk->append('log.txt', 'c');
        $disk->prepend('log.txt', 'a');
        expect($disk->get('log.txt'))->toBe('abc');

        // на несуществующий файл создают его
        $disk->append('new-append.txt', 'x');
        $disk->prepend('new-prepend.txt', 'y');
        expect($disk->get('new-append.txt'))->toBe('x');
        expect($disk->get('new-prepend.txt'))->toBe('y');
    });

    test("[$kind] json: валидный и битый", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('ok.json', '{"a":1,"b":[true,null]}');
        $disk->put('bad.json', '{broken');

        expect($disk->json('ok.json'))->toBe(['a' => 1, 'b' => [true, null]]);
        expect($disk->json('ok.json', false)->a)->toBe(1);
        expect(fn () => $disk->json('bad.json'))->toThrow(UnableToReadException::class);
    });

    test("[$kind] putStream / readStream", function () use ($kind) {
        $disk = storage_disk($kind);
        $in = fopen('php://temp', 'r+b');
        fwrite($in, 'streamed data');
        rewind($in);

        expect($disk->putStream('s/stream.txt', $in))->toBeTrue();

        $out = $disk->readStream('s/stream.txt');
        expect(stream_get_contents($out))->toBe('streamed data');
        fclose($out);
        fclose($in);
    });

    // ------------------------------------------------------------ метаданные

    test("[$kind] size/mimeType/checksum/lastModified", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('m.txt', 'hello world');

        expect($disk->size('m.txt'))->toBe(11);
        expect($disk->mimeType('m.txt'))->toBe('text/plain');
        expect($disk->checksum('m.txt'))->toBe(md5('hello world'));
        expect($disk->checksum('m.txt', 'sha256'))->toBe(hash('sha256', 'hello world'));
        expect(abs($disk->lastModified('m.txt') - time()))->toBeLessThan(5);
        expect(fn () => $disk->checksum('m.txt', 'not-an-algo'))->toThrow(UnsupportedOperationException::class);
    });

    test("[$kind] visibility: установка и чтение", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('v.txt', 'x', ['visibility' => 'public']);
        expect($disk->getVisibility('v.txt'))->toBe('public');

        $disk->setVisibility('v.txt', 'private');
        expect($disk->getVisibility('v.txt'))->toBe('private');

        expect(fn () => $disk->setVisibility('v.txt', 'secret-level'))->toThrow(UnsupportedOperationException::class);
        expect(fn () => $disk->put('w.txt', 'x', ['visibility' => 'wat']))->toThrow(UnsupportedOperationException::class);
    })->skip($kind === 'local' && !storage_posix_perms(), 'Windows: chmod не меняет права');;

    // ------------------------------------------------------------ операции

    test("[$kind] delete: идемпотентно, принимает массив", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('d1.txt', '1');
        $disk->put('d2.txt', '2');

        expect($disk->delete('d1.txt'))->toBeTrue();
        expect($disk->exists('d1.txt'))->toBeFalse();
        expect($disk->delete('d1.txt'))->toBeTrue(); // уже нет - не ошибка
        expect($disk->delete(['d2.txt', 'never-existed.txt']))->toBeTrue();
        expect($disk->exists('d2.txt'))->toBeFalse();
    });

    test("[$kind] delete директории: UnableToDeleteException", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('folder/x.txt', 'x');

        expect(fn () => $disk->delete('folder'))->toThrow(UnableToDeleteException::class);
        expect($disk->exists('folder/x.txt'))->toBeTrue();
    });

    test("[$kind] copy/move: работают и не перезаписывают существующее", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('src.txt', 'data');
        $disk->put('taken.txt', 'precious');

        $disk->copy('src.txt', 'copies/dst.txt');
        expect($disk->get('copies/dst.txt'))->toBe('data');
        expect($disk->exists('src.txt'))->toBeTrue();

        $disk->move('src.txt', 'moved/new.txt');
        expect($disk->exists('src.txt'))->toBeFalse();
        expect($disk->get('moved/new.txt'))->toBe('data');

        expect(fn () => $disk->copy('moved/new.txt', 'taken.txt'))->toThrow(FileAlreadyExistsException::class);
        expect(fn () => $disk->move('moved/new.txt', 'taken.txt'))->toThrow(FileAlreadyExistsException::class);
        expect($disk->get('taken.txt'))->toBe('precious');
        expect($disk->exists('moved/new.txt'))->toBeTrue();

        expect(fn () => $disk->copy('ghost.txt', 'x.txt'))->toThrow(FileNotFoundException::class);
    });

    test("[$kind] copy сохраняет visibility источника", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('pub.txt', 'x', ['visibility' => 'public']);
        $disk->put('priv.txt', 'x', ['visibility' => 'private']);

        $disk->copy('pub.txt', 'pub-copy.txt');
        $disk->copy('priv.txt', 'priv-copy.txt');

        expect($disk->getVisibility('pub-copy.txt'))->toBe('public');
        expect($disk->getVisibility('priv-copy.txt'))->toBe('private');
    })->skip($kind === 'local' && !storage_posix_perms(), 'Windows: chmod не меняет права');

    // ------------------------------------------------------------ директории

    test("[$kind] files/allFiles/directories/allDirectories", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('root.txt', '1');
        $disk->put('docs/a.txt', '2');
        $disk->put('docs/deep/b.txt', '3');
        $disk->put('img/c.png', '4');

        expect($disk->files())->toBe(['root.txt']);
        expect($disk->allFiles())->toBe(['docs/a.txt', 'docs/deep/b.txt', 'img/c.png', 'root.txt']);
        expect($disk->directories())->toBe(['docs', 'img']);
        expect($disk->allDirectories())->toBe(['docs', 'docs/deep', 'img']);
        expect($disk->files('docs'))->toBe(['docs/a.txt']);
        expect($disk->allFiles('docs'))->toBe(['docs/a.txt', 'docs/deep/b.txt']);
        expect($disk->directories('docs'))->toBe(['docs/deep']);
        expect($disk->files('nonexistent'))->toBe([]);
    });

    test("[$kind] makeDirectory/deleteDirectory", function () use ($kind) {
        $disk = storage_disk($kind);
        expect($disk->makeDirectory('x/y/z'))->toBeTrue();
        expect($disk->directoryExists('x/y/z'))->toBeTrue();
        expect($disk->makeDirectory('x/y/z'))->toBeTrue(); // повтор безопасен

        $disk->put('x/y/file.txt', 'f');
        expect($disk->deleteDirectory('x'))->toBeTrue();
        expect($disk->directoryExists('x'))->toBeFalse();
        expect($disk->exists('x/y/file.txt'))->toBeFalse();
        expect($disk->deleteDirectory('x'))->toBeTrue(); // повтор безопасен
    });

    // ------------------------------------------------------------ отдача клиенту

    test("[$kind] download: всегда attachment + защитные заголовки", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('report.txt', 'content');
        $res = $disk->download('report.txt', 'My Report.txt');

        expect($res)->toBeInstanceOf(StorageResponse::class);
        expect($res->status)->toBe(200);
        expect($res->headers['Content-Disposition'])->toStartWith('attachment; filename="My Report.txt"');
        expect($res->headers['X-Content-Type-Options'])->toBe('nosniff');
        expect($res->headers['Content-Length'])->toBe('7');
        expect($res->headers['Content-Type'])->toBe('text/plain');
        expect(stream_get_contents($res->stream))->toBe('content');
        fclose($res->stream);
    });

    test("[$kind] response: inline только для безопасных типов", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('note.txt', 'plain');
        $disk->put('pic.png', storage_png());
        $disk->put('page.html', '<html><script>alert(1)</script></html>');
        $disk->put('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        expect($disk->response('note.txt')->headers['Content-Disposition'])->toStartWith('inline');
        expect($disk->response('pic.png')->headers['Content-Disposition'])->toStartWith('inline');
        expect($disk->response('page.html')->headers['Content-Disposition'])->toStartWith('attachment');
        expect($disk->response('vector.svg')->headers['Content-Disposition'])->toStartWith('attachment');
        expect($disk->response('page.html')->headers['Content-Security-Policy'])->toContain('sandbox');
    });

    test("[$kind] имя файла в Content-Disposition очищается от инъекций", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('f.txt', 'x');
        $header = $disk->download('f.txt', "evil\"\r\nX-Injected: 1\\/name.txt")->headers['Content-Disposition'];

        expect($header)->not->toContain("\r");
        expect($header)->not->toContain("\n");
        expect($header)->toContain('filename="evilX-Injected: 1name.txt"');

        $unicode = $disk->download('f.txt', 'отчёт.txt')->headers['Content-Disposition'];
        expect($unicode)->toContain("filename*=UTF-8''" . rawurlencode('отчёт.txt'));
    });

    test("[$kind] пользовательские заголовки перекрывают стандартные", function () use ($kind) {
        $disk = storage_disk($kind);
        $disk->put('f.txt', 'x');
        $res = $disk->download('f.txt', null, ['Cache-Control' => 'no-store']);

        expect($res->headers['Cache-Control'])->toBe('no-store');
    });
}

// ------------------------------------------------------------------ флаги throw / report

test('throw=false: bool-методы возвращают false вместо исключения', function () {
    $disk = storage_manager(['throw' => false])->disk('local');
    $disk->put('a.txt', 'a');
    $disk->put('b.txt', 'b');

    expect($disk->copy('a.txt', 'b.txt'))->toBeFalse();
    expect($disk->move('a.txt', 'b.txt'))->toBeFalse();
    expect($disk->copy('ghost.txt', 'x.txt'))->toBeFalse();
    expect($disk->put('a.txt', 'z', ['overwrite' => false]))->toBeFalse();
    expect($disk->get('a.txt'))->toBe('a');
});

test('throw=false: не-bool методы всё равно бросают (тип не позволяет вернуть false)', function () {
    $disk = storage_manager(['throw' => false])->disk('local');

    expect(fn () => $disk->get('ghost.txt'))->toThrow(FileNotFoundException::class);
    expect(fn () => $disk->size('ghost.txt'))->toThrow(FileNotFoundException::class);
});

test('throw=true: bool-методы бросают исключения', function () {
    $disk = storage_manager(['throw' => true])->disk('local');
    $disk->put('a.txt', 'a');

    expect(fn () => $disk->copy('a.txt', 'a.txt'))->toThrow(FileAlreadyExistsException::class);
});

test('report=true: сбой уходит в репортёр с именем диска', function () {
    $reports = [];
    $disk = storage_manager(['report' => true], $root, function (\Throwable $e, array $ctx) use (&$reports) {
        $reports[] = [$e::class, $ctx];
    })->disk('local');

    $disk->exists('ok.txt');                // успех - репорта нет
    $disk->copy('ghost.txt', 'x.txt');      // сбой - репорт

    expect($reports)->toHaveCount(1);
    expect($reports[0][0])->toBe(FileNotFoundException::class);
    expect($reports[0][1])->toBe(['disk' => 'local']);
});

test('report=false: обычные сбои не репортятся, но traversal репортится всегда', function () {
    $reports = [];
    $disk = storage_manager(['report' => false], $root, function (\Throwable $e) use (&$reports) {
        $reports[] = $e::class;
    })->disk('local');

    $disk->copy('ghost.txt', 'x.txt');
    expect($reports)->toHaveCount(0);

    try {
        $disk->get('../../etc/passwd');
    } catch (PathTraversalException) {
    }

    expect($reports)->toBe([PathTraversalException::class]);
});

test('без репортёра report=true не ломает работу', function () {
    $disk = storage_manager(['report' => true, 'throw' => false])->disk('local');

    expect($disk->copy('ghost.txt', 'x.txt'))->toBeFalse();
});

// ------------------------------------------------------------------ URL / serve

test('url: serve=true даёт ссылку, имена кодируются', function () {
    $disk = storage_manager(['serve' => true])->disk('local');

    expect($disk->url('a/b.txt'))->toBe('/storage/a/b.txt');
    expect($disk->url('my file/ü.txt'))->toBe('/storage/my%20file/%C3%BC.txt');
    expect($disk->url('//a/./b.txt'))->toBe('/storage/a/b.txt');
    expect(fn () => $disk->url('../x'))->toThrow(PathTraversalException::class);
});

test('url: своя база из конфига', function () {
    $disk = storage_manager(['serve' => true, 'url' => 'https://cdn.example.com/files/'])->disk('local');

    expect($disk->url('a.txt'))->toBe('https://cdn.example.com/files/a.txt');
});

test('url: serve=false запрещает ссылки', function () {
    $disk = storage_manager(['serve' => false])->disk('local');

    expect(fn () => $disk->url('a.txt'))->toThrow(UnsupportedOperationException::class);
});

test('temporaryUrl: по умолчанию не поддерживается; свой генератор работает', function () {
    $disk = storage_manager(['serve' => true])->disk('local');
    $expires = new DateTimeImmutable('+1 hour');

    expect(fn () => $disk->temporaryUrl('a.txt', $expires))->toThrow(UnsupportedOperationException::class);
    expect(fn () => $disk->temporaryUploadUrl('a.txt', $expires))->toThrow(UnsupportedOperationException::class);

    $disk->buildTemporaryUrlsUsing(fn (string $path, DateTimeInterface $exp, array $opts): string => "/signed/{$path}?e={$exp->getTimestamp()}&o=" . count($opts));

    expect($disk->temporaryUrl('a.txt', $expires, ['x' => 1]))->toBe("/signed/a.txt?e={$expires->getTimestamp()}&o=1");
});

test('path(): абсолютный путь у local, Unsupported у memory', function () {
    $root = null;
    $manager = storage_manager(['throw' => true], $root);
    $manager->disk('local')->put('a/b.txt', 'x');

    expect($manager->disk('local')->path('a/b.txt'))->toBe($root . '/a/b.txt');
    expect(fn () => $manager->disk('memory')->path('a.txt'))->toThrow(UnsupportedOperationException::class);
});

// ------------------------------------------------------------------ между дисками

test('copyToDisk / moveToDisk', function () {
    $manager = storage_manager(['throw' => true]);
    $local = $manager->disk('local');
    $memory = $manager->disk('memory');

    $local->put('src/a.txt', 'payload');

    expect($local->copyToDisk('src/a.txt', 'memory'))->toBeTrue();
    expect($memory->get('src/a.txt'))->toBe('payload');
    expect($local->exists('src/a.txt'))->toBeTrue();

    expect($local->copyToDisk('src/a.txt', 'memory', 'other/name.txt'))->toBeTrue();
    expect($memory->get('other/name.txt'))->toBe('payload');

    expect($local->moveToDisk('src/a.txt', 'memory', 'moved.txt'))->toBeTrue();
    expect($memory->get('moved.txt'))->toBe('payload');
    expect($local->exists('src/a.txt'))->toBeFalse();
});

test('moveToDisk: источник не удаляется, если копирование не удалось', function () {
    $manager = storage_manager(['throw' => false]);
    $local = $manager->disk('local');
    $local->put('keep.txt', 'k');

    // целевой диск не существует - это ошибка конфигурации, она бросается всегда
    expect(fn () => $local->moveToDisk('keep.txt', 'no-such-disk'))
        ->toThrow(\Neo\Storage\Exceptions\DiskNotConfiguredException::class);
    expect($local->exists('keep.txt'))->toBeTrue();

    expect($local->moveToDisk('ghost.txt', 'memory'))->toBeFalse();
    expect($local->exists('keep.txt'))->toBeTrue();
});

test('copyToDisk: исходник нельзя читать через traversal', function () {
    $manager = storage_manager(['throw' => false]);

    expect(fn () => $manager->disk('local')->copyToDisk('../../etc/passwd', 'memory'))
        ->toThrow(PathTraversalException::class);
});