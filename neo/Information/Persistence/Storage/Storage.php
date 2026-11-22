<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Storage;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Information\Persistence\Storage\Contracts\DiskContract;
use Neo\Information\Persistence\Storage\Managers\StorageManager;

/**
 * @method static DiskContract disk(?string $name = null)
 * @method static DiskContract build(array $config)
 * @method static void extend(string $driver, \Closure $factory)
 * @method static DiskContract fake(?string $disk = null)
 * @method static string getDefaultDriver()
 * @method static array link(bool $force = false)
 * @method static bool removeLink(string $path)
 */
#[FacadeOf(StorageManager::class)]
class Storage extends Facade {}