<?php

declare(strict_types=1);

namespace Neo\Config;

/**
 * Переменные окружения из файла `.env` в корне проекта.
 *
 *   APP_DEBUG=true
 *   DB_HOST=127.0.0.1
 *   db.password="секрет с пробелами"     # ключ можно писать и через точку
 *   APP_URL=https://${DOMAIN}/shop       # подстановка другой переменной
 *
 * Ключи не зависят от регистра, а точка и подчёркивание
 * равнозначны: `env('db.host')`, `env('DB_HOST')`
 * и `Env::get('Db_Host')` читают одно и то же значение.
 *
 * Правила:
 *  - настоящие переменные окружения сервера сильнее файла
 *    (так на боевом сервере можно переопределить
 *    значение, не трогая файл);
 *  - строка вида `КЛЮЧ=значение`; пустые строки и строки с `#`
 *    пропускаются; можно писать `export КЛЮЧ=...`;
 *  - значение в двойных кавычках понимает `\n`, `\t`, `\"`, `\\`, `\$`
 *    и может занимать несколько строк;
 *    в одинарных кавычках берётся буквально; без кавычек обрезаются
 *    пробелы и комментарий ` # ...`;
 *  - строка, которую нельзя разобрать, это исключение с номером строки,
 *    а не молчаливый пропуск.
 *
 * Загружается один раз при старте приложения
 * ({@see Application::bootstrap()}), до чтения
 * `core/config`, поэтому в настройках достаточно писать
 * `env('КЛЮЧ', 'значение по умолчанию')`.
 */
final class Env
{
    /** @var array<string, string> Каноническое имя => значение из файлов. */
    private static array $values = [];

    /**
     * Загружает файл. Если файла нет, ничего не происходит.
     *
     * @param bool $overwrite
     * Перекрывать ли настоящие переменные окружения
     * значениями из файла.
     *
     * @return bool true, если файл был и прочитан.
     *
     * @throws \RuntimeException
     * Если в файле есть строка, которую нельзя разобрать.
     */
    public static function load(string $path, bool $overwrite = false): bool
    {
        if (!is_file($path) || ($contents = file_get_contents($path)) === false) {
            return false;
        }

        foreach (self::parse($contents, $path) as $key => $value) {
            $canonical = self::canonical($key);

            if (!$overwrite && getenv($canonical) !== false) {
                continue;
            }

            self::$values[$canonical] = $value;
        }

        return true;
    }

    /**
     * Разбирает содержимое файла `.env`.
     *
     * @return array<string, string> Ключ как в файле => значение.
     *
     * @throws \RuntimeException
     */
    public static function parse(string $contents, string $source = '.env'): array
    {
        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $lines = preg_split('/\R/', $contents) ?: [];
        $count = count($lines);
        $result = [];
        $known = [];

        $lookup = static function (string $name) use (&$known): string {
            $canonical = self::canonical($name);

            return $known[$canonical] ?? (($real = getenv($canonical)) !== false ? (string) $real : '');
        };

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                throw new \RuntimeException("{$source}, строка " . ($i + 1) . ': ожидается КЛЮЧ=значение.');
            }

            [, $key, $rest] = $m;
            $startLine = $i + 1;

            if ($rest !== '' && ($rest[0] === '"' || $rest[0] === "'")) {
                $quote = $rest[0];
                $buffer = substr($rest, 1);

                // Значение в кавычках может продолжаться на следующих строках.
                while (($end = self::closingQuote($buffer, $quote)) === null) {
                    if (++$i >= $count) {
                        throw new \RuntimeException("{$source}, строка {$startLine}: не закрыта кавычка.");
                    }

                    $buffer .= "\n" . $lines[$i];
                }

                $after = trim(substr($buffer, $end + 1));

                if ($after !== '' && $after[0] !== '#') {
                    throw new \RuntimeException("{$source}, строка {$startLine}: после закрывающей кавычки есть лишний текст.");
                }

                $raw = substr($buffer, 0, $end);
                $value = $quote === "'" ? $raw : self::expand($raw, $lookup, true);
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $rest));
                $value = self::expand($value, $lookup, false);
            }

            $result[$key] = $value;
            $known[self::canonical($key)] = $value;
        }

        return $result;
    }

    /**
     * Значение переменной. Слова `true`, `false`, `null`
     * и `empty` (в том числе в скобках) превращаются
     * в true, false, null и пустую строку; всё остальное
     * остаётся строкой.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::raw($key);

        if ($value === null) {
            return $default instanceof \Closure ? $default() : $default;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /**
     * Значение как есть, без превращения слов в
     * true/false/null. Для паролей и ключей.
     */
    public static function raw(string $key, ?string $default = null): ?string
    {
        $canonical = self::canonical($key);

        if (array_key_exists($canonical, self::$values)) {
            return self::$values[$canonical];
        }

        $real = getenv($canonical);

        return $real === false ? $default : (string) $real;
    }

    public static function has(string $key): bool
    {
        return self::raw($key) !== null;
    }

    /**
     * Логическое значение: `1`, `true`, `yes`, `on`
     * это true; `0`, `false`, `no`, `off`, пусто это false.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::raw($key);

        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', '(true)', 'yes', 'on' => true,
            '0', 'false', '(false)', 'no', 'off', '', 'null', '(null)', 'empty', '(empty)' => false,
            default => $default,
        };
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::raw($key);

        return $value !== null && preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) $value : $default;
    }

    /**
     * Задаёт значение вручную (в тестах и скриптах).
     */
    public static function set(string $key, string $value): void
    {
        self::$values[self::canonical($key)] = $value;
    }

    /**
     * Забывает всё, что загружено из файлов
     * (настоящие переменные окружения остаются).
     */
    public static function flush(): void
    {
        self::$values = [];
    }

    /**
     * Каноническое имя: заглавные буквы, точка заменена
     * подчёркиванием.
     */
    public static function canonical(string $key): string
    {
        return strtoupper(str_replace('.', '_', $key));
    }

    /**
     * Записывает или заменяет `КЛЮЧ=значение` в файле,
     * не трогая остальные строки и комментарии.
     * Файл создаётся, если его нет. Запись атомарная.
     *
     * @throws \RuntimeException|\Random\RandomException Если файл не удалось записать.
     */
    public static function writeToFile(string $path, string $key, string $value): void
    {
        $lines = is_file($path) ? (preg_split('/\R/', (string) file_get_contents($path)) ?: []) : [];

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $canonical = self::canonical($key);
        $encoded = $key . '=' . (preg_match('/^[A-Za-z0-9_.:\/@+\-=,]*$/', $value) === 1 ? $value : '"' . addcslashes($value, "\"\\\$") . '"');
        $replaced = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $m) === 1 && self::canonical($m[1]) === $canonical) {
                $lines[$i] = $encoded;
                $replaced = true;
                break;
            }
        }

        if (!$replaced) {
            $lines[] = $encoded;
        }

        $temporary = $path . '.tmp' . bin2hex(random_bytes(3));

        if (file_put_contents($temporary, implode("\n", $lines) . "\n") === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException("Не удалось записать файл {$path}.");
        }

        self::$values[$canonical] = $value;
    }

    // ------------------------------------------------------------------

    /**
     * Позиция закрывающей кавычки (в двойных учитывается
     * экранирование обратной косой чертой).
     */
    private static function closingQuote(string $text, string $quote): ?int
    {
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            if ($quote === '"' && $text[$i] === '\\') {
                $i++;
            } elseif ($text[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Подставляет `${ИМЯ}` и, в двойных кавычках,
     * обрабатывает экранирование.
     *
     * @param \Closure(string): string $lookup
     */
    private static function expand(string $text, \Closure $lookup, bool $escapes): string
    {
        $out = '';
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($escapes && $char === '\\' && $i + 1 < $length) {
                $next = $text[++$i];
                $out .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $next,
                };

                continue;
            }

            if ($char === '$' && ($text[$i + 1] ?? '') === '{' && ($close = strpos($text, '}', $i)) !== false) {
                $name = substr($text, $i + 2, $close - $i - 2);

                if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) === 1) {
                    $out .= $lookup($name);
                    $i = $close;

                    continue;
                }
            }

            $out .= $char;
        }

        return $out;
    }
}