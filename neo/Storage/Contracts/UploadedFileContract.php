<?php

namespace Neo\Storage\Contracts;

interface UploadedFileContract
{
    // оригинал от клиента (недоверенное)
    public function getClientOriginalName(): string;
    public function getClientOriginalExtension(): string;
    public function getClientMimeType(): ?string;

    // определённое сервером (доверенное)
    public function getMimeType(): ?string;
    public function extension(): ?string;
    public function guessExtension(): ?string;
    public function getSize(): int|false;

    // состояние
    public function isValid(): bool;
    public function getError(): int;
    public function getErrorMessage(): string;

    // доступ к файлу
    public function path(): string;       // realpath временного файла
    public function stream();             // resource
    public function hashName(?string $path = null): string;

    // сохранение
    public function store(string $path, array|string $options = []): string|false;
    public function storeAs(string $path, string $name, array|string $options = []): string|false;
    public function storePublicly(string $path, array|string $options = []): string|false;
    public function storePubliclyAs(string $path, string $name, array|string $options = []): string|false;

    // картинки
    public function image(): ImageContract;
}