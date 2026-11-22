<?php

declare(strict_types=1);

namespace Neo\Information\Cache\Contracts;

interface CacheStoreContract extends
    CacheContract,
    IncrementableCacheContract
{
}