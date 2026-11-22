<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;

final class File extends Provider
{
    private const MIME_TYPES = [
        'text/plain' => ['txt'],
        'text/html' => ['html'],
        'text/csv' => ['csv'],
        'application/json' => ['json'],
        'application/pdf' => ['pdf'],
        'application/zip' => ['zip'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'video/mp4' => ['mp4'],
        'audio/mpeg' => ['mp3'],
    ];

    public function mimeType(): string
    {
        return (string) $this->rng()->pickKey(self::MIME_TYPES);
    }

    public function fileExtension(): string
    {
        return (string) $this->rng()->pick($this->rng()->pick(self::MIME_TYPES));
    }

    public function fileName(?string $extension = null): string
    {
        return $this->faker->slug(2) . '.' . ($extension ?? $this->fileExtension());
    }

    /** Placeholder image URL of the given size. */
    public function imageUrl(int $width = 640, int $height = 480): string
    {
        return "https://placehold.co/{$width}x{$height}";
    }
}
