<?php

declare(strict_types=1);

/**
 * Хелперы тестов Neo\Storage. Подключается раннером перед остальными файлами.
 */

use Neo\Storage\Contracts\DiskContract;
use Neo\Storage\Domain\UploadedFile;
use Neo\Storage\Managers\StorageManager;
use Neo\Storage\Transfers\StorageTransfer;

/** Рекурсивно удаляет путь (симлинки удаляет сами, в цель не заходит). */
function storage_rm(string $path): void
{
    if (is_link($path)) {
        // симлинк на папку в Windows удаляется только через rmdir
        @unlink($path) || @rmdir($path);

        return;
    }

    if (is_file($path)) {
        @unlink($path);

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            storage_rm($path . '/' . $item);
        }
    }

    @rmdir($path);
}

/** Создаёт уникальную временную папку; удаляется при завершении процесса. */
function storage_tmp_dir(): string
{
    $dir = sys_get_temp_dir() . '/neo-storage-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    register_shutdown_function(static fn() => storage_rm($dir));

    return storage_path(realpath($dir));
}

/**
 * Менеджер с одним локальным диском "local" (по умолчанию) и memory-диском "memory".
 *
 * @param array<string, mixed> $local доп. опции local-диска (serve, throw, report, ...)
 */
function storage_manager(array $local = [], ?string &$root = null, ?\Closure $reporter = null, array $links = []): StorageManager
{
    $root ??= storage_tmp_dir() . '/root';

    try {
        return new StorageManager(new StorageTransfer([
            'default' => 'local',
            'disks' => [
                'local' => array_merge(['driver' => 'local', 'root' => $root], $local),
                'memory' => ['driver' => 'memory', 'throw' => true, 'serve' => true],
            ],
            'links' => $links,
        ]), $reporter);
    } catch (\Neo\Validator\ValidationException $e) {
        throw new \RuntimeException('Validation: ' . json_encode($e->errors(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, $e);
    }
}

/**
 * Пара драйверов для одинаковых тестов: local и memory.
 * throw=true, чтобы сбои были видны как исключения.
 *
 * @return array<string, DiskContract>
 */
function storage_disks(): array
{
    $manager = storage_manager(['throw' => true, 'serve' => true]);

    return ['local' => $manager->disk('local'), 'memory' => $manager->disk('memory')];
}

/** Свежий изолированный диск нужного вида: 'local' или 'memory'. */
function storage_disk(string $kind): DiskContract
{
    return storage_disks()[$kind];
}

/** Создаёт UploadedFile из строки (во временном файле, режим test). */
function storage_upload(string $contents, string $clientName = 'file.txt', ?string $clientMime = null, ?\Neo\Storage\Contracts\StorageManagerContract $manager = null, int $error = UPLOAD_ERR_OK): UploadedFile
{
    $tmp = storage_tmp_dir() . '/upload.tmp';
    file_put_contents($tmp, $contents);

    return new UploadedFile($tmp, $clientName, $clientMime, $error, true, $manager);
}

/** Валидный PNG заданного размера (опционально полностью прозрачный). */
function storage_png(int $width = 20, int $height = 10, bool $transparent = false): string
{
    $img = imagecreatetruecolor($width, $height);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    if ($transparent) {
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    } else {
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    }

    ob_start();
    imagepng($img);

    return (string)ob_get_clean();
}

/** Валидный JPEG; $noise=true делает "шумную" картинку (для проверки quality). */
function storage_jpeg(int $width = 40, int $height = 20, bool $noise = false): string
{
    $img = imagecreatetruecolor($width, $height);

    if ($noise) {
        mt_srand(42);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
    } else {
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
    }

    ob_start();
    imagejpeg($img, null, 90);

    return (string)ob_get_clean();
}

/** Вставляет JPEG-сегмент сразу после SOI (FF D8). */
function storage_jpeg_with_segment(string $jpeg, int $marker, string $payload): string
{
    return substr($jpeg, 0, 2) . "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
}

/** JPEG с реальным EXIF-тегом Orientation (little-endian TIFF). */
function storage_jpeg_with_orientation(string $jpeg, int $orientation): string
{
    $tiff = 'II' . pack('v', 42) . pack('V', 8)        // заголовок TIFF, смещение IFD0 = 8
        . pack('v', 1)                                   // одна запись в IFD0
        . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . "\x00\x00"
        . pack('V', 0);                                  // нет следующего IFD

    return storage_jpeg_with_segment($jpeg, 0xE1, "Exif\x00\x00" . $tiff);
}

/** Добавляет в PNG текстовый чанк tEXt перед IEND (имитация "мусора"/метаданных). */
function storage_png_with_text(string $png, string $keyword, string $text): string
{
    $data = $keyword . "\x00" . $text;
    $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
    $iend = strrpos($png, 'IEND') - 4;

    return substr($png, 0, $iend) . $chunk . substr($png, $iend);
}

/** Реальные POSIX-права доступны только не на Windows. */
function storage_posix_perms(): bool
{
    return DIRECTORY_SEPARATOR === '/';
}

/** Приводит путь к прямым слешам (readlink/realpath на Windows отдают обратные). */
function storage_path(string $path): string
{
    return str_replace('\\', '/', $path);
}
