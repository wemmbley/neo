<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax;

use LogicException;
use Neo\Information\Markdown\Contracts\SyntaxContract;
use Neo\Transfer\Transfer;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Соглашения (convention over configuration). Каждое можно переопределить
 * либо в классе, либо при регистрации: registerSyntax($syntax, $transfer, $template).
 *
 *  - имя        — короткое имя класса без `Syntax` в kebab-case:
 *                 HeaderOneSyntax => `header-one`, VideoSyntax => `video`;
 *  - шаблон     — `<ИмяКласса>.blade.php` рядом с файлом класса;
 *  - Transfer   — тип первого параметра конструктора.
 */
abstract class BasicSyntax implements SyntaxContract
{
    public static function name(): string
    {
        $short = (new ReflectionClass(static::class))->getShortName();
        $base = preg_replace('~Syntax$~', '', $short);
        $base = $base === null || $base === '' ? $short : $base;

        return strtolower((string) preg_replace('~(?<=[a-z0-9])(?=[A-Z])~', '-', $base));
    }

    public static function priority(): int
    {
        return 100;
    }

    public static function template(): string
    {
        $reflection = new ReflectionClass(static::class);

        return dirname((string) $reflection->getFileName())
            . DIRECTORY_SEPARATOR
            . $reflection->getShortName()
            . '.blade.php';
    }

    public static function transfer(): string
    {
        $type = self::configParameter()->getType();

        if (
            !$type instanceof ReflectionNamedType
            || $type->isBuiltin()
            || !is_subclass_of($type->getName(), Transfer::class)
        ) {
            throw new LogicException(sprintf(
                '%s: the first constructor parameter must be typed with a Transfer subclass.',
                static::class,
            ));
        }

        return $type->getName();
    }

    public function config(): Transfer
    {
        return $this->{self::configParameter()->getName()};
    }

    public function data(): array
    {
        return self::export($this->config());
    }

    /**
     * @return array<string, mixed>
     */
    private static function export(object $object): array
    {
        $data = [];

        foreach (get_object_vars($object) as $key => $value) {
            $data[$key] = is_object($value) ? self::export($value) : $value;
        }

        return $data;
    }

    private static function configParameter(): ReflectionParameter
    {
        $parameter = (new ReflectionClass(static::class))->getConstructor()?->getParameters()[0] ?? null;

        if ($parameter === null) {
            throw new LogicException(sprintf(
                '%s must declare a constructor that accepts its Transfer as the first parameter.',
                static::class,
            ));
        }

        return $parameter;
    }
}
