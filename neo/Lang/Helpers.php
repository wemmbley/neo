<?php

use Neo\Lang\LangProcessor;

/**
 * Возвращает перевод по ключу "namespace::file.key".
 *
 * @param array<string, scalar> $replace подстановки для ":name"
 */
if (!function_exists('__')) {
    function __(
        string $key,
        array $replace = [],
        ?string $locale = null
    ): mixed
    {
        return LangProcessor::instance()->get($key, $replace, $locale);
    }
}