<?php

declare(strict_types=1);

use Neo\Cache\Managers\CacheManager;
use Neo\Cache\Stores\ArrayStore;
use Neo\Cache\Stores\FileStore;
use Neo\Cache\Transfers\CacheTransfer;
use Neo\Storage\Contracts\DiskContract;
use Neo\Storage\Managers\StorageManager;
use Neo\Storage\Transfers\StorageTransfer;

/**
 * Управляемые часы: стор читает время из ->time, тесты двигают его вручную (без sleep).
 */
function cache_clock(int $start = 1_700_000_000): stdClass
{
    $clock = new stdClass();
    $clock->time = $start;

    return $clock;
}

/**
 * Варианты стора, на которых прогоняется общее поведение.
 *
 * @return list<string>
 */
function cache_store_kinds(): array
{
    return ['array', 'file-memory', 'file-local', 'file-local-quiet'];
}

function cache_tmp_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neo-cache-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    register_shutdown_function(static function () use ($dir): void {
        cache_rmdir($dir);
    });

    return $dir;
}

function cache_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

/**
 * StorageManager с диском 'memory' и (по запросу) 'local' во временной папке.
 * $throw относится только к local-диску: false проверяет путь «тихих» диска.
 */
function cache_storage(string $default = 'memory', bool $throw = true): StorageManager
{
    $disks = ['memory' => ['driver' => 'memory']];

    if ($default === 'local') {
        $disks['local'] = ['driver' => 'local', 'root' => cache_tmp_dir(), 'throw' => $throw];
    }

    return new StorageManager(new StorageTransfer([
        'default' => $default,
        'disks' => $disks,
        'links' => [],
    ]));
}

function cache_disk(string $kind = 'memory', bool $throw = true): DiskContract
{
    return cache_storage($kind, $throw)->disk();
}

function cache_array(?stdClass $clock = null, string $prefix = '', bool $serialize = true): ArrayStore
{
    return new class($clock, $prefix, $serialize) extends ArrayStore {
        public function __construct(private ?stdClass $clock, string $prefix, bool $serialize)
        {
            parent::__construct($prefix, $serialize);
        }

        protected function now(): int
        {
            return $this->clock?->time ?? time();
        }
    };
}

function cache_file(
    DiskContract $disk,
    ?stdClass $clock = null,
    string $prefix = '',
    string $directory = 'cache',
): FileStore {
    return new class($disk, $directory, $prefix, $clock) extends FileStore {
        public function __construct(DiskContract $disk, string $directory, string $prefix, private ?stdClass $clock)
        {
            parent::__construct($disk, $directory, $prefix);
        }

        protected function now(): int
        {
            return $this->clock?->time ?? time();
        }
    };
}

function cache_store(string $kind, ?stdClass $clock = null, string $prefix = ''): \Neo\Cache\Stores\BasicStore
{
    return match ($kind) {
        'array' => cache_array($clock, $prefix),
        'file-memory' => cache_file(cache_disk('memory'), $clock, $prefix),
        'file-local' => cache_file(cache_disk('local'), $clock, $prefix),
        'file-local-quiet' => cache_file(cache_disk('local', false), $clock, $prefix),
    };
}

/**
 * @param array<string, array> $stores
 */
function cache_manager(
    array $stores,
    string $default = 'array',
    string $prefix = 'neo',
    ?StorageManager $storage = null,
): CacheManager {
    return new CacheManager(new CacheTransfer([
        'default' => $default,
        'prefix' => $prefix,
        'stores' => $stores,
    ]), $storage);
}