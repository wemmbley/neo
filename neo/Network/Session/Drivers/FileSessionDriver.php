<?php

declare(strict_types=1);

namespace Neo\Network\Session\Drivers;

use Neo\Information\Persistence\Storage\Contracts\DiskContract;
use Neo\Information\Persistence\Storage\Exceptions\FileNotFoundException;
use Neo\Network\Session\Contracts\SessionDriverContract;

final class FileSessionDriver implements SessionDriverContract
{
    public function __construct(
        private readonly DiskContract $disk,
        private readonly string $directory = '',
        private readonly int $lifetime = 7200,
    ) {}

    public function read(string $id): ?array
    {
        try {
            $raw = $this->disk->get($this->path($id));
        } catch (FileNotFoundException) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['data'], $decoded['last_activity']) || !is_array($decoded['data'])) return null;
        if ((int) $decoded['last_activity'] < time() - $this->lifetime) {
            $this->destroy($id);
            return null;
        }
        return $decoded['data'];
    }

    public function write(string $id, array $data, int $lastActivity): void
    {
        $this->disk->put($this->path($id), json_encode([
            'data' => $data,
            'last_activity' => $lastActivity,
        ], JSON_THROW_ON_ERROR), ['overwrite' => true]);
    }

    public function destroy(string $id): void
    {
        try { $this->disk->delete($this->path($id)); } catch (FileNotFoundException) {}
    }

    public function gc(int $lifetime): int
    {
        $removed = 0;
        $cutoff = time() - $lifetime;
        foreach ($this->disk->allFiles($this->directory === '' ? null : $this->directory) as $path) {
            try {
                $payload = json_decode($this->disk->get($path), true);
                if (!is_array($payload) || (int) ($payload['last_activity'] ?? 0) < $cutoff) {
                    $this->disk->delete($path);
                    $removed++;
                }
            } catch (\Throwable) {
                $this->disk->delete($path);
                $removed++;
            }
        }
        return $removed;
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{40,128}$/', $id)) {
            throw new \InvalidArgumentException('Invalid session identifier.');
        }
        $directory = trim($this->directory, '/');
        return ($directory === '' ? '' : $directory . '/') . $id . '.session';
    }
}
