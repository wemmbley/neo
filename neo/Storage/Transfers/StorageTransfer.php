<?php

namespace Neo\Storage\Transfers;

use Neo\Transfer\BasicTransfer;
use Neo\Validator\Rule;

class StorageTransfer extends BasicTransfer
{
    public string $default;

    public array $disks;

    public array $links = [];

    public function validateDefault(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->string()
            ->min(1);
    }

    public function validateDisks(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->associative()
            ->min(1)
            ->values(function (Rule $disk): Rule {
                return $disk->shape([
                    // driver и root проверяет StorageManager (DiskNotConfiguredException),
                    // у memory-диска root вообще нет
                    'driver?' => fn (Rule $rule) => $rule->string(),
                    'root?' => fn (Rule $rule) => $rule->string(),

                    'serve?' => fn (Rule $rule) => $rule->bool(),
                    'throw?' => fn (Rule $rule) => $rule->bool(),
                    'report?' => fn (Rule $rule) => $rule->bool(),
                    'url?' => fn (Rule $rule) => $rule->string(),
                    'visibility?' => fn (Rule $rule) => $rule->string(),
                ]);
            });
    }

    public function validateLinks(Rule $rule): Rule
    {
        return $rule
            ->array();
    }
}