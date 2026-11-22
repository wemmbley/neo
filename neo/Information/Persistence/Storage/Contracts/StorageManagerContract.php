<?php

namespace Neo\Information\Persistence\Storage\Contracts;

interface StorageManagerContract
{
    // null = диск по умолчанию
    public function disk(?string $name = null): DiskContract;

    // диск из конфига на лету
    public function build(array $config): DiskContract;

    // регистрация своих драйверов
    public function extend(string $driver, \Closure $factory): void;

    // для тестов
    public function fake(?string $disk = null): DiskContract;
    public function getDefaultDriver(): string;
}