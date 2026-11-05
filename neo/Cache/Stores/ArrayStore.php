<?php

declare(strict_types=1);

namespace Neo\Cache\Stores;

use Neo\Cache\Contracts\PrunableContract;

/**
 * Кэш в памяти процесса. Для тестов, CLI-воркеров и как L1 поверх других сторов.
 *
 * $serialize = true (по умолчанию): значения хранятся сериализованными,
 * поэтому объект из кэша — копия, как и в FileStore. false — хранит по ссылке.
 */
class ArrayStore extends BasicStore implements PrunableContract
{
    /** @var array<string, array{value: mixed, expires: ?int}> */
    private array $items = [];

    public function __construct(string $prefix = '', private readonly bool $serialize = true)
    {
        parent::__construct($prefix);
    }

    protected function fetch(string $key): ?array
    {
        $item = $this->items[$this->prefixed($key)] ?? null;

        if ($item === null) {
            return null;
        }

        if ($this->serialize) {
            $item['value'] = unserialize($item['value']);
        }

        return $item;
    }

    protected function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        $this->items[$this->prefixed($key)] = [
            'value' => $this->serialize ? $this->encode($key, $value) : $value,
            'expires' => $expiresAt,
        ];

        return true;
    }

    protected function remove(string $key): bool
    {
        unset($this->items[$this->prefixed($key)]);

        return true;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    public function prune(): int
    {
        $now = $this->now();
        $removed = 0;

        foreach ($this->items as $key => $item) {
            if ($item['expires'] !== null && $item['expires'] <= $now) {
                unset($this->items[$key]);
                $removed++;
            }
        }

        return $removed;
    }
}