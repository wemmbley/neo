<?php

declare(strict_types=1);

namespace Neo\Security\Xss;

use InvalidArgumentException;
use Stringable;

/**
 * Экранирование вывода: основная защита от XSS.
 *
 * Правило: данные пользователя экранируются в МОМЕНТ вывода, под контекст, в который они
 * попадают. Единой «очистки на входе» не существует: tag stripping и чёрные списки обходятся.
 *
 *   html()  текст между тегами          <p>{{ html($name) }}</p>
 *   attr()  значение атрибута в кавычках <input value="{{ attr($v) }}">
 *   js()    значение внутри <script>     var x = {{ js($v) }};  (готовый JSON-литерал)
 *   url()   компонент URL                /search?q={{ url($q) }}
 *
 * Дополнительные слои: CSP с nonce ({@see \Neo\Security\Headers\SecurityHeaders}) и HttpOnly-куки.
 */
final class Escaper
{
    public static function html(mixed $value, bool $doubleEncode = true): string
    {
        return htmlspecialchars(self::string($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
    }

    /** Для значения атрибута всегда используйте кавычки: без них экранирования недостаточно. */
    public static function attr(mixed $value): string
    {
        return self::html($value);
    }

    /**
     * JSON-литерал, безопасный внутри <script> и в атрибутах: `<`, `>`, `&`, кавычки
     * кодируются, поэтому `</script>` в данных не закрывает тег.
     */
    public static function js(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public static function url(mixed $value): string
    {
        return rawurlencode(self::string($value));
    }

    /**
     * Пропускает только http(s), mailto, tel и относительные ссылки; остальное
     * (`javascript:`, `data:`, `vbscript:`) заменяется на `#`. Результат всё равно нужно
     * экранировать через attr().
     */
    public static function safeUrl(mixed $value): string
    {
        $url = trim(self::string($value));
        # Браузеры игнорируют пробелы и управляющие символы внутри схемы: «java\tscript:».
        $probe = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $url);

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $probe, $m) === 1) {
            return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true) ? $url : '#';
        }

        return $url;
    }

    private static function string(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_scalar($value) || $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException('Для экранирования нужна строка или скаляр, получено ' . get_debug_type($value) . '.'),
        };
    }
}
