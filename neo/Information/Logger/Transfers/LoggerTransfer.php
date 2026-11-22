<?php

namespace Neo\Information\Logger\Transfers;

use Neo\Information\Transfer\Transfer;
use Neo\Information\Validator\Rule;

class LoggerTransfer extends Transfer
{
    public string $default = 'single';

    public array $drivers = [];

    public array $channels = [];

    public function validateDefault(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->string()
            ->min(1);
    }

    public function validateDrivers(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->array();
    }

    public function validateChannels(Rule $rule): Rule
    {
        return $rule
            ->required()
            ->array();
    }
}