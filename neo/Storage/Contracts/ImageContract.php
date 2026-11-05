<?php

namespace Neo\Storage\Contracts;

interface ImageContract
{
    // проверка и защита
    public function validate(int $maxWidth = 10000, int $maxHeight = 10000): static; // защита от «бомб»
    public function reencode(): static;      // та самая киллер-фича: срезает EXIF и мусор
    public function orientate(): static;     // поворот по EXIF, до reencode

    // трансформации
    public function resize(?int $width, ?int $height, bool $keepRatio = true): static;
    public function cover(int $width, int $height): static;
    public function crop(int $width, int $height, int $x = 0, int $y = 0): static;
    public function quality(int $quality): static;

    // форматы
    public function toWebp(int $quality = 85): static;
    public function toJpeg(int $quality = 85): static;
    public function toPng(): static;

    // инфо
    public function width(): int;
    public function height(): int;
    public function mimeType(): string;

    // вывод
    public function toString(): string;
    public function save(string $path): bool;
    public function store(string $directory, ?string $disk = null): string|false;
    public function storeAs(string $directory, string $name, ?string $disk = null): string|false;
}