<?php

namespace Neo\Information\Persistence\Storage\Transfers;

use Neo\Information\Transfer\Transfer;
use Neo\Information\Validator\Rule;

class StorageTransfer extends Transfer
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