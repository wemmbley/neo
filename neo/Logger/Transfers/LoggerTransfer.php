<?php

namespace Neo\Logger\Transfers;

use Neo\Transfer\BasicTransfer;
use Neo\Validator\Rule;

class LoggerTransfer extends BasicTransfer
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