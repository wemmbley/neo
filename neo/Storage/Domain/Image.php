<?php

declare(strict_types=1);

namespace Neo\Storage\Domain;

use Neo\Storage\Contracts\ImageContract;
use Neo\Storage\Contracts\StorageManagerContract;
use Neo\Storage\Exceptions\InvalidImageException;
use Neo\Storage\Exceptions\UnsupportedOperationException;

/**
 * Работа с картинками на GD (без зависимостей).
 *
 * Безопасность:
 *  - тип и размеры читаются из заголовка ДО декодирования (защита от "бомб");
 *  - toString() всегда перекодирует пиксели заново, поэтому EXIF, ICC, комментарии
 *    и любой "мусор" в файле никогда не попадают в результат (reencode "по умолчанию");
 *  - принимаются только JPEG / PNG / GIF / WEBP; SVG и прочее отклоняются.
 *
 * Ограничение: GIF сохраняется как первый кадр (GD не умеет анимацию).
 */
class Image implements ImageContract
{
    private const TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Жёсткий предел размера входных данных (32 MiB). */
    private const MAX_BYTES = 33554432;

    private ?\GdImage $gd = null;
    private string $sourceMime;
    private string $mime;
    private int $sourceWidth;
    private int $sourceHeight;
    private int $quality = 85;
    private int $limitWidth = 10000;
    private int $limitHeight = 10000;
    private bool $oriented = false;

    private function __construct(
        private readonly string $binary,
        private readonly ?StorageManagerContract $storage,
    ) {
        if (!extension_loaded('gd')) {
            throw new UnsupportedOperationException('The GD extension is required for image processing.');
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw new InvalidImageException('Image is too large.');
        }

        // Читает только заголовок, пиксели не декодирует.
        $info = @getimagesizefromstring($binary);

        if ($info === false || !isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidImageException('Unsupported or corrupted image.');
        }

        $this->sourceWidth = $info[0];
        $this->sourceHeight = $info[1];
        $this->sourceMime = $this->mime = self::TYPES[$info[2]];
    }

    /**
     * @throws InvalidImageException
     */
    public static function fromString(string $binary, ?StorageManagerContract $storage = null): static
    {
        return new static($binary, $storage);
    }

    // ---------------------------------------------------------------- проверка и защита

    /** Бросает InvalidImageException, если картинка больше лимита. Лимит действует и при декодировании. */
    public function validate(int $maxWidth = 10000, int $maxHeight = 10000): static
    {
        $this->limitWidth = $maxWidth;
        $this->limitHeight = $maxHeight;

        if ($this->width() > $maxWidth || $this->height() > $maxHeight) {
            throw new InvalidImageException("Image exceeds {$maxWidth}x{$maxHeight} pixels.");
        }

        return $this;
    }

    /** Полное декодирование в пиксели: всё, что не пиксели (EXIF и т.п.), отбрасывается. */
    public function reencode(): static
    {
        $this->gd();

        return $this;
    }

    /** Поворот по EXIF-ориентации. Читает EXIF из исходника, поэтому порядок с reencode() не важен. */
    public function orientate(): static
    {
        if ($this->oriented || $this->sourceMime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $this;
        }

        $this->oriented = true;

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $this->binary);
        rewind($stream);
        $exif = @exif_read_data($stream, 'IFD0');
        fclose($stream);

        $gd = $this->gd();

        switch (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
            case 2: imageflip($gd, IMG_FLIP_HORIZONTAL); break;
            case 3: $this->gd = imagerotate($gd, 180, 0) ?: $gd; break;
            case 4: imageflip($gd, IMG_FLIP_VERTICAL); break;
            case 5: imageflip($gd, IMG_FLIP_HORIZONTAL); $this->gd = imagerotate($gd, 90, 0) ?: $gd; break;
            case 6: $this->gd = imagerotate($gd, -90, 0) ?: $gd; break;
            case 7: imageflip($gd, IMG_FLIP_HORIZONTAL); $this->gd = imagerotate($gd, -90, 0) ?: $gd; break;
            case 8: $this->gd = imagerotate($gd, 90, 0) ?: $gd; break;
        }

        return $this;
    }

    // ---------------------------------------------------------------- трансформации

    public function resize(?int $width, ?int $height, bool $keepRatio = true): static
    {
        if ($width === null && $height === null) {
            throw new InvalidImageException('Specify at least width or height.');
        }

        $currentWidth = $this->width();
        $currentHeight = $this->height();

        if ($keepRatio && $width !== null && $height !== null) {
            // Вписываем в рамку width x height.
            $scale = min($width / $currentWidth, $height / $currentHeight);
            $width = (int) round($currentWidth * $scale);
            $height = (int) round($currentHeight * $scale);
        } elseif ($keepRatio) {
            $width ??= (int) round($currentWidth * $height / $currentHeight);
            $height ??= (int) round($currentHeight * $width / $currentWidth);
        } else {
            $width ??= $currentWidth;
            $height ??= $currentHeight;
        }

        return $this->transform(max(1, $width), max(1, $height), 0, 0, $currentWidth, $currentHeight);
    }

    /** Заполняет рамку целиком и обрезает лишнее по центру. */
    public function cover(int $width, int $height): static
    {
        $currentWidth = $this->width();
        $currentHeight = $this->height();
        $scale = max($width / $currentWidth, $height / $currentHeight);

        $cropWidth = min($currentWidth, max(1, (int) round($width / $scale)));
        $cropHeight = min($currentHeight, max(1, (int) round($height / $scale)));

        return $this->transform(
            $width,
            $height,
            intdiv($currentWidth - $cropWidth, 2),
            intdiv($currentHeight - $cropHeight, 2),
            $cropWidth,
            $cropHeight,
        );
    }

    public function crop(int $width, int $height, int $x = 0, int $y = 0): static
    {
        if ($x < 0 || $y < 0 || $x + $width > $this->width() || $y + $height > $this->height()) {
            throw new InvalidImageException('Crop area is outside of the image.');
        }

        return $this->transform($width, $height, $x, $y, $width, $height);
    }

    public function quality(int $quality): static
    {
        $this->quality = max(1, min(100, $quality));

        return $this;
    }

    // ---------------------------------------------------------------- форматы

    public function toWebp(int $quality = 85): static
    {
        if (!function_exists('imagewebp')) {
            throw new UnsupportedOperationException('This GD build has no WEBP support.');
        }

        $this->mime = 'image/webp';

        return $this->quality($quality);
    }

    public function toJpeg(int $quality = 85): static
    {
        $this->mime = 'image/jpeg';

        return $this->quality($quality);
    }

    public function toPng(): static
    {
        $this->mime = 'image/png';

        return $this;
    }

    // ---------------------------------------------------------------- инфо

    public function width(): int
    {
        return $this->gd !== null ? imagesx($this->gd) : $this->sourceWidth;
    }

    public function height(): int
    {
        return $this->gd !== null ? imagesy($this->gd) : $this->sourceHeight;
    }

    public function mimeType(): string
    {
        return $this->mime;
    }

    // ---------------------------------------------------------------- вывод

    /** Всегда кодирует из пикселей - метаданные исходника не переживают. */
    public function toString(): string
    {
        $gd = $this->gd();
        $out = '';

        ob_start();

        try {
            $ok = match ($this->mime) {
                'image/jpeg' => imagejpeg($this->sourceMime === 'image/jpeg' ? $gd : $this->flatten($gd), null, $this->quality),
                'image/png' => imagepng($gd, null, 6),
                'image/gif' => imagegif($gd),
                'image/webp' => imagewebp($gd, null, $this->quality),
            };
        } finally {
            $out = (string) ob_get_clean();
        }

        if (!$ok || $out === '') {
            throw new InvalidImageException('Failed to encode the image.');
        }

        return $out;
    }

    /**
     * Сохраняет в файл по ПРЯМОМУ пути файловой системы.
     * Путь не проверяется: передавайте только доверенное значение (для пользовательских
     * путей используйте store()/storeAs() через диск).
     */
    public function save(string $path): bool
    {
        return file_put_contents($path, $this->toString(), LOCK_EX) !== false;
    }

    public function store(string $directory, ?string $disk = null): string|false
    {
        return $this->storeAs($directory, bin2hex(random_bytes(20)) . '.' . self::EXTENSIONS[$this->mime], $disk);
    }

    public function storeAs(string $directory, string $name, ?string $disk = null): string|false
    {
        $target = $this->storage?->disk($disk)
            ?? throw new UnsupportedOperationException('Image was created without a storage manager.');

        $path = PathNormalizer::join($directory, PathNormalizer::filename($name));

        return $target->put($path, $this->toString()) ? $path : false;
    }

    // ---------------------------------------------------------------- внутреннее

    /** Ленивое декодирование: до этого момента в памяти только исходные байты. */
    private function gd(): \GdImage
    {
        if ($this->gd === null) {
            if ($this->sourceWidth > $this->limitWidth || $this->sourceHeight > $this->limitHeight) {
                throw new InvalidImageException("Image exceeds {$this->limitWidth}x{$this->limitHeight} pixels.");
            }

            $image = @imagecreatefromstring($this->binary);

            if (!$image instanceof \GdImage) {
                throw new InvalidImageException('Unable to decode the image.');
            }

            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);
            $this->gd = $image;
        }

        return $this->gd;
    }

    /** Общий примитив: копирует область исходника в новый холст нужного размера. */
    private function transform(int $width, int $height, int $srcX, int $srcY, int $srcWidth, int $srcHeight): static
    {
        // Защита от "resize-бомбы": результат не больше разрешённого лимита.
        if ($width > $this->limitWidth || $height > $this->limitHeight) {
            throw new InvalidImageException("Result exceeds {$this->limitWidth}x{$this->limitHeight} pixels.");
        }

        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        if (!imagecopyresampled($canvas, $this->gd(), 0, 0, $srcX, $srcY, $width, $height, $srcWidth, $srcHeight)) {
            throw new InvalidImageException('Image transformation failed.');
        }

        $this->gd = $canvas;

        return $this;
    }

    /** JPEG не умеет прозрачность: кладём картинку на белый фон. */
    private function flatten(\GdImage $source): \GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        return $canvas;
    }
}