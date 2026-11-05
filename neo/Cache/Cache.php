<?php

namespace Neo\Cache;

use Neo\Cache\Managers\CacheManager;
use Neo\Kernel\Facade;
use Neo\Kernel\FacadeOf;

#[FacadeOf(CacheManager::class)]
class Cache extends Facade {}