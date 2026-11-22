<?php

/**
 * Возвращает перевод по ключу "namespace::file.key".
 *
 * @param array<string, scalar> $replace подстановки для ":name"
 */

use Neo\Information\Lang\LangProcessor;

if (!function_exists('__')) {
    function __(string $key, array $replace = [], ?string $locale = null): mixed
    {
        return kernel(LangProcessor::class)->get($key, $replace, $locale);
    }
}