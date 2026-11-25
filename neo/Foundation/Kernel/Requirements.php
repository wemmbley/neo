<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

final class Requirements
{
    public static function check(array $requires): void
    {
        $problems = self::problems($requires);

        if ($problems !== []) {
            throw new RequirementsException(self::message($problems));
        }
    }

    public static function problems(array $requires): array
    {
        $problems = [];
        $missing = [];

        foreach ($requires as $key => $value) {
            if ($key === 'php_ext') {
                foreach ((array) $value as $extension) {
                    if (!extension_loaded((string) $extension)) {
                        $missing[] = (string) $extension;
                    }
                }

                continue;
            }

            if ($key === 'php_functions') {
                foreach ((array) $value as $function) {
                    if (!function_exists((string) $function)) {
                        $missing[] = (string) $function;
                    }
                }

                continue;
            }

            if (is_int($key)) {
                $problem = self::checkVersion((string) $value);

                if ($problem !== null) {
                    $problems[] = $problem;
                }

                continue;
            }

            throw new \InvalidArgumentException("Неизвестный пункт requires: \"{$key}\".");
        }

        if ($missing !== []) {
            $problems[] = 'не подключены расширения PHP: ' . implode(', ', array_unique($missing));
        }

        return $problems;
    }

    private static function checkVersion(string $rule): ?string
    {
        if (preg_match('/^php\s*(>=|<=|==|!=|>|<)\s*([0-9][\w.\-]*)$/i', trim($rule), $m) !== 1) {
            throw new \InvalidArgumentException(
                "Не удалось разобрать требование \"{$rule}\". Ожидается вид \"php >= 8.5\"."
            );
        }

        return version_compare(PHP_VERSION, $m[2], $m[1])
            ? null
            : "нужна версия PHP {$m[1]} {$m[2]}, установлена " . PHP_VERSION;
    }

    private static function message(array $problems): string
    {
        $ini = php_ini_loaded_file() ?: 'php.ini не найден';

        return "Окружение не подходит для запуска приложения:\n"
            . '  - ' . implode("\n  - ", $problems) . "\n"
            . "Исправьте настройки ({$ini}), например extension=имя, и перезапустите PHP.\n"
            . 'Веб-сервер и консоль часто используют разные php.ini.';
    }
}

final class RequirementsException extends \RuntimeException {}