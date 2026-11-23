<?php

declare(strict_types=1);

namespace Neo\Network\Session\Contracts;

interface SessionDriverContract
{
    /** @return array<string, mixed>|null */
    public function read(string $id): ?array;

    /** @param array<string, mixed> $data */
    public function write(string $id, array $data, int $lastActivity): void;

    public function destroy(string $id): void;

    public function gc(int $lifetime): int;
}
