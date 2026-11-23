<?php

declare(strict_types=1);

namespace Neo\Network\Session\Drivers;

use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Network\Session\Contracts\SessionDriverContract;

final class DatabaseSessionDriver implements SessionDriverContract
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly string $table = 'sessions',
        private readonly ?string $connection = null,
        private readonly int $lifetime = 7200,
    ) {}

    public function read(string $id): ?array
    {
        $row = $this->database->connection($this->connection)->table($this->table)->where('id', $id)->first();
        if ($row === null) return null;
        if ((int) ($row['last_activity'] ?? 0) < time() - $this->lifetime) {
            $this->destroy($id);
            return null;
        }
        $data = json_decode((string) ($row['payload'] ?? ''), true);
        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data, int $lastActivity): void
    {
        $connection = $this->database->connection($this->connection);
        $values = ['payload' => json_encode($data, JSON_THROW_ON_ERROR), 'last_activity' => $lastActivity];
        $exists = $connection->table($this->table)->where('id', $id)->exists();
        if ($exists) {
            $connection->table($this->table)->where('id', $id)->update($values);
            return;
        }
        $connection->table($this->table)->insert(['id' => $id, ...$values]);
    }

    public function destroy(string $id): void
    {
        $this->database->connection($this->connection)->table($this->table)->where('id', $id)->delete();
    }

    public function gc(int $lifetime): int
    {
        return $this->database->connection($this->connection)->table($this->table)
            ->where('last_activity', '<', time() - $lifetime)->delete();
    }
}
