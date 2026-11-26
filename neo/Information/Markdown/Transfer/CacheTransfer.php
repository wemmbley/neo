<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Transfer;

use Neo\Transfer\Transfer;
use Neo\Validator\Rule;

class CacheTransfer extends Transfer
{
    # Состояние кэша: вкл-выкл.
    #
    public bool $enabled = true;

    # Время жизни кэша в секундах. 0 — бессрочно (до изменения файла).
    #
    public int $ttl = 86400;

    # Директория кэширования. Обязательна только при включённом кэше.
    #
    public string $path = '';

    public function validateTtl(Rule $rule): Rule
    {
        return $rule->int()->min(0);
    }

    public function validatePath(Rule $rule): Rule|bool
    {
        if (!$this->enabled) {
            return true;
        }

        return $rule
            ->required()
            ->string()
            ->writable();
    }
}
