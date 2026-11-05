<?php

namespace Neo\Storage\Contracts\ShouldProvide;

interface ShouldProvideTemporaryUrlContract
{
    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string;
    public function temporaryUploadUrl(string $path, \DateTimeInterface $expiration, array $options = []): array;
}