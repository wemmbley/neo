<?php

declare(strict_types=1);

namespace Neo\Storage\Domain;

use Neo\Storage\Contracts\ImageContract;
use Neo\Storage\Contracts\StorageManagerContract;
use Neo\Storage\Contracts\UploadedFileContract;
use Neo\Storage\Exceptions\UnableToReadException;
use Neo\Storage\Exceptions\UnableToWriteException;
use Neo\Storage\Exceptions\UnsupportedOperationException;

/**
 * Загруженный файл.
 *
 * Правило безопасности: всё, что пришло от клиента (имя, расширение, mime),
 * считается недоверенным. Расширение сохранённого файла определяется ТОЛЬКО
 * по содержимому (finfo), а имя на диске - случайное (hashName).
 */
class UploadedFile implements UploadedFileContract
{
    /** Доверенное соответствие mime => расширение. Намеренно короткое: неизвестное = null. */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/json' => 'json', 'application/zip' => 'zip',
        'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4',
    ];

    private const ERRORS = [
        UPLOAD_ERR_OK => 'The file was uploaded successfully.',
        UPLOAD_ERR_INI_SIZE => 'The file exceeds the upload_max_filesize directive.',
        UPLOAD_ERR_FORM_SIZE => 'The file exceeds the MAX_FILE_SIZE directive of the form.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
    ];

    /**
     * @param bool $test true только в тестах: пропускает is_uploaded_file()
     */
    public function __construct(
        private readonly string $path,
        private readonly string $originalName,
        private readonly ?string $clientMimeType = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $test = false,
        private readonly ?StorageManagerContract $storage = null,
    ) {
    }

    /**
     * Создаёт файл из одной записи $_FILES['field'].
     * Множественные загрузки передавайте по одной записи.
     */
    public static function fromGlobals(array $entry, ?StorageManagerContract $storage = null): static
    {
        if (is_array($entry['tmp_name'] ?? null)) {
            throw new UnsupportedOperationException('Pass multiple uploads one entry at a time.');
        }

        return new static(
            (string) ($entry['tmp_name'] ?? ''),
            (string) ($entry['name'] ?? ''),
            isset($entry['type']) ? (string) $entry['type'] : null,
            (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
            false,
            $storage,
        );
    }

    // ---------------------------------------------------------------- от клиента (недоверенное)

    /** Имя от клиента, очищенное от путей и управляющих символов. Только для показа, не для хранения. */
    public function getClientOriginalName(): string
    {
        $name = basename(str_replace('\\', '/', $this->originalName));

        return preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
    }

    public function getClientOriginalExtension(): string
    {
        return strtolower(pathinfo($this->getClientOriginalName(), PATHINFO_EXTENSION));
    }

    public function getClientMimeType(): ?string
    {
        return $this->clientMimeType;
    }

    //  определённое сервером (доверенное)

    public function getMimeType(): ?string
    {
        if (!$this->isValid()) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($this->path);

        return $mime !== false ? $mime : null;
    }

    /** Доверенное расширение (по содержимому). */
    public function extension(): ?string
    {
        return $this->guessExtension();
    }

    public function guessExtension(): ?string
    {
        $mime = $this->getMimeType();

        return $mime === null ? null : (self::MIME_EXTENSIONS[$mime] ?? null);
    }

    public function getSize(): int|false
    {
        return $this->isValid() ? (@filesize($this->path) ?: 0) : false;
    }

    // --------------------------- состояние

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && ($this->test ? is_file($this->path) : is_uploaded_file($this->path));
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getErrorMessage(): string
    {
        return self::ERRORS[$this->error] ?? 'Unknown upload error.';
    }

    // -------------------------- доступ к файлу

    public function path(): string
    {
        $this->assertValid();

        return realpath($this->path) ?: throw new UnableToReadException('Uploaded file is not readable.');
    }

    public function stream()
    {
        $this->assertValid();
        $stream = @fopen($this->path, 'rb');

        return $stream === false ? throw new UnableToReadException('Uploaded file is not readable.') : $stream;
    }

    /** Случайное имя + доверенное расширение. Клиентское имя не участвует. */
    public function hashName(?string $path = null): string
    {
        $name = bin2hex(random_bytes(20));

        if (($extension = $this->extension()) !== null) {
            $name .= '.' . $extension;
        }

        return $path === null ? $name : PathNormalizer::join($path, $name);
    }

    // --------------------------------- сохранение

    /**
     * @param array|string $options строка = имя диска; массив может содержать 'disk'
     */
    public function store(string $path, array|string $options = []): string|false
    {
        [$disk, $options] = $this->splitOptions($options);

        return $this->save($path, $this->hashName(), $disk, $options);
    }

    public function storeAs(string $path, string $name, array|string $options = []): string|false
    {
        [$disk, $options] = $this->splitOptions($options);

        return $this->save($path, $name, $disk, $options);
    }

    public function storePublicly(string $path, array|string $options = []): string|false
    {
        [$disk, $options] = $this->splitOptions($options);

        return $this->save($path, $this->hashName(), $disk, $options + ['visibility' => 'public']);
    }

    public function storePubliclyAs(string $path, string $name, array|string $options = []): string|false
    {
        [$disk, $options] = $this->splitOptions($options);

        return $this->save($path, $name, $disk, $options + ['visibility' => 'public']);
    }

    // ------------------------------------- картинки

    public function image(): ImageContract
    {
        $data = @file_get_contents($this->path());

        return $data === false
            ? throw new UnableToReadException('Uploaded file is not readable.')
            : Image::fromString($data, $this->storage);
    }

    // ---------------------------- внутреннее

    /** @return array{0: ?string, 1: array} */
    private function splitOptions(array|string $options): array
    {
        if (is_string($options)) {
            return [$options, []];
        }

        $disk = $options['disk'] ?? null;
        unset($options['disk']);

        return [$disk, $options];
    }

    private function save(string $directory, string $name, ?string $disk, array $options): string|false
    {
        $target = $this->storage?->disk($disk)
            ?? throw new UnsupportedOperationException('UploadedFile was created without a storage manager.');

        try {
            return $target->putFileAs($directory, $this, $name, $options);
        } catch (UnableToWriteException $e) {
            // Контракт допускает false, но уважаем флаг throw диска.
            if ($target instanceof Disk && $target->throws()) {
                throw $e;
            }

            return false;
        }
    }

    private function assertValid(): void
    {
        if (!$this->isValid()) {
            throw new UnableToReadException('Upload is not valid: ' . $this->getErrorMessage());
        }
    }
}