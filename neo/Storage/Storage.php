<?php

declare(strict_types=1);

namespace Neo\Storage;

use Neo\Kernel\Facade;
use Neo\Kernel\FacadeOf;
use Neo\Storage\Managers\StorageManager;

#[FacadeOf(StorageManager::class)]
class Storage extends Facade
{
}