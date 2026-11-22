<?php

declare(strict_types=1);

namespace Neo\Information\Cache\Transfers;

use Neo\Information\Transfer\Transfer;
use Neo\Information\Validator\Rule;

class CacheTransfer extends Transfer
{
    public string $default;

    public string $prefix;

    public array $stores;

    public function validateDefault(Rule $default): Rule
    {
        return $default
            ->string()
            ->required();
    }

    public function validatePrefix(Rule $prefix): Rule
    {
        return $prefix
            ->string()
            ->required();
    }

    public function validateStores(Rule $stores): Rule
    {
        return $stores
            ->array()
            ->required();
    }
}