<?php

declare(strict_types=1);

namespace Neo\Network\Http;

/** Заставляет браузер скачать файл (Content-Disposition: attachment). */
class FileDownloadResponse extends FileResponse
{
    public function __construct(string $path, ?string $name = null, array $headers = [], int $status = 200)
    {
        parent::__construct($path, $status, $headers, 'attachment', $name);
    }
}
