<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Transfers;

use Closure;
use Neo\Information\Transfer\Transfer;
use Neo\Information\Validator\Rule;

/**
 * Настройки кэша шаблонов (ключ 'cache' в конфиге).
 *
 * Типы enabled и lifetime проверяются самими типами свойств.
 */
class CacheTransfer extends Transfer
{
    public bool $enabled = false;
    public string $strategy = 'directive';
    public string $path = '';
    public int $lifetime = 0;

    /**
     * full — кэшируется вывод ВСЕХ шаблонов; directive — только блоки @cache.
     */
    public function validateStrategy(Rule $rule): Rule
    {
        return $rule->string()->custom(function (string $attribute, string $value, Closure $fail): void {
            if (!in_array($value, ['full', 'directive'], true)) {
                $fail(":attribute: допустимо только full или directive, получено «{$value}»");
            }
        });
    }

    /**
     * Если кэш включён — папка должна быть доступна для записи
     * (Renderer сам создаёт вложенные compiled/output/fragments).
     */
    public function validatePath(Rule $rule): Rule
    {
        $rule = $rule->string();

        return $this->enabled
            ? $rule->notEmpty()->writable()
            : $rule;
    }

    /**
     * Секунды. 0 — бессрочно до инвалидации.
     */
    public function validateLifetime(Rule $rule): Rule
    {
        return $rule->custom(function (string $attribute, int $value, Closure $fail): void {
            if ($value < 0) {
                $fail(':attribute: не может быть отрицательным');
            }
        });
    }
}
