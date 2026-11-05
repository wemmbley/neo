<?php

declare(strict_types=1);

use Neo\Storage\Exceptions\InvalidImageException;
use Neo\Storage\Exceptions\PathTraversalException;
use Neo\Storage\Exceptions\UnableToReadException;
use Neo\Storage\Exceptions\UnableToWriteException;
use Neo\Storage\Exceptions\UnsupportedOperationException;
use Neo\Storage\Domain\UploadedFile;
use Neo\Storage\Domain\Image;

require_once 'TestHelpers.php';

// ================================================================== UploadedFile

test('upload: клиентское имя очищается от путей и управляющих символов', function () {
    expect(storage_upload('x', '../../etc/evil.php')->getClientOriginalName())->toBe('evil.php');
    expect(storage_upload('x', 'C:\\Users\\me\\virus.exe')->getClientOriginalName())->toBe('virus.exe');
    expect(storage_upload('x', "na\0me\r\n.txt")->getClientOriginalName())->toBe('name.txt');
    expect(storage_upload('x', 'Photo.JPG')->getClientOriginalExtension())->toBe('jpg');
});

test('upload: расширение определяется по СОДЕРЖИМОМУ, а не по клиентскому имени', function () {
    $png = storage_upload(storage_png(), 'shell.php', 'application/x-php');
    expect($png->getMimeType())->toBe('image/png');
    expect($png->extension())->toBe('png');
    expect($png->guessExtension())->toBe('png');
    expect($png->hashName())->toEndWith('.png');
    expect($png->hashName())->not->toContain('php');

    $txt = storage_upload("just text\n", 'photo.jpg', 'image/jpeg');
    expect($txt->extension())->toBe('txt');
    expect($txt->hashName())->toEndWith('.txt');

    $html = storage_upload('<html><body>hi</body></html>', 'a.png', 'image/png');
    expect($html->hashName())->not->toEndWith('.png');
});

test('upload: неизвестный бинарный тип => имя без расширения', function () {
    $file = storage_upload(random_bytes(256) . "\x00\x01\x02", 'x.bin');
    $name = $file->hashName();

    expect($file->extension())->toBeNull();
    expect($name)->toMatch('/^[0-9a-f]{40}$/');
});

test('upload: hashName случайное и с префиксом пути', function () {
    $file = storage_upload('text');

    expect($file->hashName())->not->toBe($file->hashName());
    expect($file->hashName('dir/sub'))->toMatch('#^dir/sub/[0-9a-f]{40}\.txt$#');
    expect(fn () => $file->hashName('../up'))->toThrow(PathTraversalException::class);
});

test('upload: client mime возвращается как есть, но ему не доверяем', function () {
    $file = storage_upload('text', 'a.txt', 'image/png');

    expect($file->getClientMimeType())->toBe('image/png');
    expect($file->getMimeType())->toBe('text/plain');
});

test('upload: состояние, ошибки и размер', function () {
    $ok = storage_upload('12345');
    expect($ok->isValid())->toBeTrue();
    expect($ok->getError())->toBe(UPLOAD_ERR_OK);
    expect($ok->getSize())->toBe(5);
    expect(is_string($ok->path()))->toBeTrue();

    $bad = storage_upload('12345', 'a.txt', null, null, UPLOAD_ERR_INI_SIZE);
    expect($bad->isValid())->toBeFalse();
    expect($bad->getError())->toBe(UPLOAD_ERR_INI_SIZE);
    expect($bad->getErrorMessage())->toContain('upload_max_filesize');
    expect($bad->getSize())->toBeFalse();
    expect($bad->getMimeType())->toBeNull();
    expect(fn () => $bad->path())->toThrow(UnableToReadException::class);
    expect(fn () => $bad->stream())->toThrow(UnableToReadException::class);
});

test('upload: обычный файл НЕ считается загруженным (без test-режима)', function () {
    $tmp = storage_tmp_dir() . '/forged.tmp';
    file_put_contents($tmp, 'x');

    // is_uploaded_file() защищает от подмены tmp_name на произвольный путь.
    $forged = new UploadedFile($tmp, 'x.txt', null, UPLOAD_ERR_OK, false);

    expect($forged->isValid())->toBeFalse();
    expect(fn () => $forged->stream())->toThrow(UnableToReadException::class);
});

test('upload: fromGlobals и защита от массивов', function () {
    $file = UploadedFile::fromGlobals(['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/nope', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]);
    expect($file->isValid())->toBeFalse();
    expect($file->getClientOriginalName())->toBe('a.txt');

    expect(fn () => UploadedFile::fromGlobals(['name' => ['a'], 'tmp_name' => ['/x'], 'error' => [0]]))
        ->toThrow(UnsupportedOperationException::class);
});

test('upload: store сохраняет под случайным именем в нужную папку', function () {
    $root = null;
    $manager = storage_manager(['throw' => true], $root);
    $file = storage_upload('hello', 'my.txt', null, $manager);

    $path = $file->store('uploads/2026');

    expect($path)->toMatch('#^uploads/2026/[0-9a-f]{40}\.txt$#');
    expect(file_get_contents($root . '/' . $path))->toBe('hello');
});

test('upload: storeAs / storePublicly / storePubliclyAs и выбор диска строкой', function () {
    $root = null;
    $manager = storage_manager(['throw' => true, 'serve' => false], $root);
    $disk = $manager->disk('local');

    $as = storage_upload('data', 'x.txt', null, $manager)->storeAs('docs', 'fixed.txt');
    expect($as)->toBe('docs/fixed.txt');

    if (storage_posix_perms()) {
        expect($disk->getVisibility($as))->toBe('private');
    }

    $public = storage_upload('data', 'x.txt', null, $manager)->storePublicly('pub');
    expect($disk->getVisibility($public))->toBe('public');

    $publicAs = storage_upload('data', 'x.txt', null, $manager)->storePubliclyAs('pub', 'n.txt');
    expect($publicAs)->toBe('pub/n.txt');
    expect($disk->getVisibility($publicAs))->toBe('public');

    $viaString = storage_upload('mem', 'x.txt', null, $manager)->store('m', 'memory');
    expect($manager->disk('memory')->get($viaString))->toBe('mem');

    $viaArray = storage_upload('mem2', 'x.txt', null, $manager)->storeAs('m', 'a.txt', ['disk' => 'memory']);
    expect($manager->disk('memory')->get($viaArray))->toBe('mem2');
});

test('upload: storeAs с traversal в имени или папке отклоняется', function () {
    $root = null;
    $manager = storage_manager(['throw' => false], $root);

    foreach (['../evil.txt', 'a/b.txt', '..'] as $name) {
        expect(fn () => storage_upload('x', 'a.txt', null, $manager)->storeAs('safe', $name))
            ->toThrow(PathTraversalException::class);
    }

    expect(fn () => storage_upload('x', 'a.txt', null, $manager)->store('../outside'))
        ->toThrow(PathTraversalException::class);
    expect(file_exists(dirname($root) . '/evil.txt'))->toBeFalse();
});

test('upload: store невалидной загрузки: false при throw=false, исключение при throw=true', function () {
    $soft = storage_manager(['throw' => false]);
    $hard = storage_manager(['throw' => true]);

    $badSoft = storage_upload('x', 'a.txt', null, $soft, UPLOAD_ERR_PARTIAL);
    $badHard = storage_upload('x', 'a.txt', null, $hard, UPLOAD_ERR_PARTIAL);

    expect($badSoft->store('dir'))->toBeFalse();
    expect(fn () => $badHard->store('dir'))->toThrow(UnableToWriteException::class);
});

test('upload: без менеджера сохранить нельзя', function () {
    expect(fn () => storage_upload('x')->store('dir'))->toThrow(UnsupportedOperationException::class);
});

test('upload: disk->put/putFile принимает UploadedFile', function () {
    $manager = storage_manager(['throw' => true]);
    $disk = $manager->disk('local');

    $disk->put('exact/path.txt', storage_upload('exact'));
    expect($disk->get('exact/path.txt'))->toBe('exact');

    $stored = $disk->putFile('auto', storage_upload('auto'));
    expect($stored)->toMatch('#^auto/[0-9a-f]{40}\.txt$#');
    expect($disk->get($stored))->toBe('auto');

    expect($disk->putFileAs('named', storage_upload('n'), 'n.txt'))->toBe('named/n.txt');
});

// ================================================================== Image: безопасность

test('image: отклоняет не-картинки, SVG и повреждённые данные', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';

    foreach (['not an image', '', $svg, '<?php echo 1;', substr(storage_png(), 0, 30), "GIF89a" . random_bytes(8)] as $junk) {
        expect(fn () => Image::fromString($junk)->toString())->toThrow(InvalidImageException::class);
    }
});

test('image: отклоняет слишком большие входные данные', function () {
    $huge = storage_png() . str_repeat('A', 33 * 1024 * 1024);

    expect(fn () => Image::fromString($huge))->toThrow(InvalidImageException::class);
});

test('image: validate() отсекает "бомбы" по заголовку, до декодирования', function () {
    $image = Image::fromString(storage_png(300, 200));

    expect($image->validate(300, 200)->width())->toBe(300);
    expect(fn () => Image::fromString(storage_png(300, 200))->validate(299, 200))->toThrow(InvalidImageException::class);
    expect(fn () => Image::fromString(storage_png(300, 200))->validate(300, 199))->toThrow(InvalidImageException::class);
});

test('image: лимит из validate() действует и на последующее декодирование/результат', function () {
    $image = Image::fromString(storage_png(50, 50))->validate(100, 100);

    expect(fn () => $image->resize(101, null))->toThrow(InvalidImageException::class);
    expect(fn () => Image::fromString(storage_png(50, 50))->resize(20000, null))->toThrow(InvalidImageException::class);
});

test('image: toString() вычищает метаданные PNG (tEXt)', function () {
    $dirty = storage_png_with_text(storage_png(), 'Comment', 'SECRET-PNG-METADATA');
    expect($dirty)->toContain('SECRET-PNG-METADATA');

    $clean = Image::fromString($dirty)->toString();

    expect($clean)->not->toContain('SECRET-PNG-METADATA');
    expect($clean)->toStartWith("\x89PNG");
});

test('image: toString() вычищает EXIF и комментарии JPEG', function () {
    $jpeg = storage_jpeg();
    $dirty = storage_jpeg_with_segment($jpeg, 0xE1, "Exif\x00\x00SECRET-GPS-COORDINATES");
    $dirty = storage_jpeg_with_segment($dirty, 0xFE, 'SECRET-COMMENT');
    expect($dirty)->toContain('SECRET-GPS-COORDINATES');
    expect($dirty)->toContain('SECRET-COMMENT');

    $clean = Image::fromString($dirty)->toString();

    expect($clean)->not->toContain('SECRET-GPS-COORDINATES');
    expect($clean)->not->toContain('SECRET-COMMENT');
    expect($clean)->toStartWith("\xFF\xD8");
});

test('image: данные, дописанные после конца файла, не переживают перекодирование', function () {
    $dirty = storage_png() . '<?php system($_GET["c"]); ?>';
    $clean = Image::fromString($dirty)->reencode()->toString();

    expect($clean)->not->toContain('<?php');
    expect($clean)->not->toContain('system(');
});

test('image: результат всегда валидная картинка того же размера', function () {
    $out = Image::fromString(storage_png(33, 17))->toString();
    $info = getimagesizefromstring($out);

    expect($info[0])->toBe(33);
    expect($info[1])->toBe(17);
});

// ================================================================== Image: трансформации

test('image: инфо о размере и типе', function () {
    $png = Image::fromString(storage_png(20, 10));
    $jpg = Image::fromString(storage_jpeg(40, 20));

    expect($png->width())->toBe(20);
    expect($png->height())->toBe(10);
    expect($png->mimeType())->toBe('image/png');
    expect($jpg->mimeType())->toBe('image/jpeg');
});

test('image: resize с сохранением пропорций', function () {
    $make = fn () => Image::fromString(storage_png(100, 50));

    $byWidth = $make()->resize(50, null);
    expect([$byWidth->width(), $byWidth->height()])->toBe([50, 25]);

    $byHeight = $make()->resize(null, 10);
    expect([$byHeight->width(), $byHeight->height()])->toBe([20, 10]);

    $fit = $make()->resize(40, 40); // вписать в рамку
    expect([$fit->width(), $fit->height()])->toBe([40, 20]);

    $stretch = $make()->resize(40, 40, false);
    expect([$stretch->width(), $stretch->height()])->toBe([40, 40]);

    $oneSide = $make()->resize(30, null, false);
    expect([$oneSide->width(), $oneSide->height()])->toBe([30, 50]);

    expect(fn () => $make()->resize(null, null))->toThrow(InvalidImageException::class);
});

test('image: cover заполняет рамку целиком', function () {
    $wide = Image::fromString(storage_png(100, 50))->cover(30, 30);
    expect([$wide->width(), $wide->height()])->toBe([30, 30]);

    $tall = Image::fromString(storage_png(50, 100))->cover(40, 20);
    expect([$tall->width(), $tall->height()])->toBe([40, 20]);

    // результат декодируется и содержит те же пиксели (однотонная красная картинка)
    $out = imagecreatefromstring($wide->toString());
    $rgb = imagecolorat($out, 15, 15);
    expect(($rgb >> 16) & 0xFF)->toBe(200);
});

test('image: crop и проверка границ', function () {
    $cropped = Image::fromString(storage_png(100, 50))->crop(10, 20, 5, 5);
    expect([$cropped->width(), $cropped->height()])->toBe([10, 20]);

    foreach ([[10, 10, 95, 0], [10, 10, 0, 45], [200, 10, 0, 0], [10, 10, -1, 0], [10, 10, 0, -1]] as [$w, $h, $x, $y]) {
        expect(fn () => Image::fromString(storage_png(100, 50))->crop($w, $h, $x, $y))->toThrow(InvalidImageException::class);
    }
});

test('image: форматы и сигнатуры файлов', function () {
    $asJpeg = Image::fromString(storage_png())->toJpeg();
    expect($asJpeg->mimeType())->toBe('image/jpeg');
    expect($asJpeg->toString())->toStartWith("\xFF\xD8");

    $asPng = Image::fromString(storage_jpeg())->toPng();
    expect($asPng->mimeType())->toBe('image/png');
    expect($asPng->toString())->toStartWith("\x89PNG");

    if (function_exists('imagewebp')) {
        $webp = Image::fromString(storage_png())->toWebp(80);
        expect($webp->mimeType())->toBe('image/webp');
        $bytes = $webp->toString();
        expect(substr($bytes, 0, 4))->toBe('RIFF');
        expect(substr($bytes, 8, 4))->toBe('WEBP');
    }
});

test('image: quality влияет на размер JPEG', function () {
    $noisy = storage_jpeg(120, 120, true);
    $low = strlen(Image::fromString($noisy)->quality(10)->toString());
    $high = strlen(Image::fromString($noisy)->quality(95)->toString());

    expect($low)->toBeLessThan($high);
});

test('image: прозрачный PNG при конвертации в JPEG ложится на белый фон', function () {
    $out = Image::fromString(storage_png(10, 10, true))->toJpeg(95)->toString();
    $rgb = imagecolorat(imagecreatefromstring($out), 5, 5);

    expect(($rgb >> 16) & 0xFF)->toBeGreaterThan(240);
    expect(($rgb >> 8) & 0xFF)->toBeGreaterThan(240);
    expect($rgb & 0xFF)->toBeGreaterThan(240);
});

test('image: прозрачность PNG сохраняется', function () {
    $out = Image::fromString(storage_png(10, 10, true))->resize(5, 5)->toString();
    $alpha = (imagecolorat(imagecreatefromstring($out), 2, 2) >> 24) & 0x7F;

    expect($alpha)->toBe(127);
});

test('image: orientate поворачивает по EXIF Orientation', function () {
    $base = storage_jpeg(40, 20);

    foreach ([6, 8, 5, 7] as $orientation) { // эти значения меняют ширину и высоту местами
        $image = Image::fromString(storage_jpeg_with_orientation($base, $orientation))->orientate();
        expect([$image->width(), $image->height()])->toBe([20, 40]);
    }

    foreach ([1, 2, 3, 4] as $orientation) { // размеры сохраняются
        $image = Image::fromString(storage_jpeg_with_orientation($base, $orientation))->orientate();
        expect([$image->width(), $image->height()])->toBe([40, 20]);
    }
});

test('image: orientate без EXIF и для не-JPEG ничего не меняет, повторный вызов безопасен', function () {
    $plain = Image::fromString(storage_jpeg(40, 20))->orientate();
    expect([$plain->width(), $plain->height()])->toBe([40, 20]);

    $png = Image::fromString(storage_png(40, 20))->orientate();
    expect([$png->width(), $png->height()])->toBe([40, 20]);

    $twice = Image::fromString(storage_jpeg_with_orientation(storage_jpeg(40, 20), 6))->orientate()->orientate();
    expect([$twice->width(), $twice->height()])->toBe([20, 40]);
});

test('image: цепочка вызовов даёт ожидаемый результат', function () {
    $out = Image::fromString(storage_jpeg_with_orientation(storage_jpeg(400, 200), 6))
        ->validate(1000, 1000)
        ->orientate()
        ->reencode()
        ->cover(64, 64)
        ->toJpeg(80)
        ->toString();

    $info = getimagesizefromstring($out);
    expect([$info[0], $info[1]])->toBe([64, 64]);
    expect($info['mime'])->toBe('image/jpeg');
});

// ================================================================== Image: вывод и сохранение

test('image: save пишет файл по прямому пути', function () {
    $path = storage_tmp_dir() . '/out.png';

    expect(Image::fromString(storage_png(8, 8))->save($path))->toBeTrue();
    expect(getimagesize($path)[0])->toBe(8);
});

test('image: store / storeAs через диск', function () {
    $root = null;
    $manager = storage_manager(['throw' => true], $root);

    $path = Image::fromString(storage_png(8, 8), $manager)->toJpeg()->store('thumbs');
    expect($path)->toMatch('#^thumbs/[0-9a-f]{40}\.jpg$#');
    expect(getimagesize($root . '/' . $path)['mime'])->toBe('image/jpeg');

    $named = Image::fromString(storage_png(8, 8), $manager)->storeAs('thumbs', 'fixed.png');
    expect($named)->toBe('thumbs/fixed.png');

    $mem = Image::fromString(storage_png(8, 8), $manager)->storeAs('t', 'm.png', 'memory');
    expect(getimagesizefromstring($manager->disk('memory')->get($mem))[0])->toBe(8);
});

test('image: storeAs защищён от traversal; без менеджера сохранить нельзя', function () {
    $manager = storage_manager(['throw' => true]);

    foreach (['../evil.png', 'a/b.png', '..'] as $name) {
        expect(fn () => Image::fromString(storage_png(), $manager)->storeAs('t', $name))->toThrow(PathTraversalException::class);
    }

    expect(fn () => Image::fromString(storage_png())->store('t'))->toThrow(UnsupportedOperationException::class);
});

test('upload->image(): цепочка от загруженного файла до сохранения', function () {
    $root = null;
    $manager = storage_manager(['throw' => true], $root);
    $dirty = storage_png_with_text(storage_png(200, 100), 'Author', 'SECRET-AUTHOR');

    $path = storage_upload($dirty, 'avatar.php.png', 'image/png', $manager)
        ->image()
        ->validate(1000, 1000)
        ->cover(32, 32)
        ->toWebp()
        ->store('avatars');

    if (!function_exists('imagewebp')) {
        return;
    }

    $saved = file_get_contents($root . '/' . $path);
    expect($path)->toMatch('#^avatars/[0-9a-f]{40}\.webp$#');
    expect($saved)->not->toContain('SECRET-AUTHOR');
    expect(getimagesizefromstring($saved)[0])->toBe(32);
});

test('upload->image(): не-картинка отклоняется', function () {
    $file = storage_upload('<?php echo 1;', 'fake.png', 'image/png', storage_manager());

    expect(fn () => $file->image())->toThrow(InvalidImageException::class);
});