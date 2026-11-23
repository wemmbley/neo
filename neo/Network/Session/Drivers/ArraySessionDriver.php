<?php

declare(strict_types=1);

namespace Neo\Network\Session\Drivers;

use Neo\Network\Session\Contracts\SessionDriverContract;

final class ArraySessionDriver implements SessionDriverContract
{
    private array $sessions = [];

    public function __construct(private readonly int $lifetime = 7200) {}

    public function read(string $id): ?array
    {
        $session = $this->sessions[$id] ?? null;
        if ($session === null) return null;
        if ($session['last_activity'] < time() - $this->lifetime) {
            unset($this->sessions[$id]);
            return null;
        }
        return $session['data'];
    }

    public function write(string $id, array $data, int $lastActivity): void
    {
        $this->sessions[$id] = ['data' => $data, 'last_activity' => $lastActivity];
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function gc(int $lifetime): int
    {
        $cutoff = time() - $lifetime;
        $before = count($this->sessions);
        foreach ($this->sessions as $id => $session) {
            if ($session['last_activity'] < $cutoff) unset($this->sessions[$id]);
        }
        return $before - count($this->sessions);
    }
}
