<?php

declare(strict_types=1);

namespace Neo\Transfer;

use Closure;
use LogicException;
use Neo\Validator\Rule;
use Neo\Validator\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use TypeError;

/**
 * Базовый DTO с автомаппингом и автовалидацией.
 *
 * Конструктор принимает массив данных, раскладывает его по публичным
 * свойствам наследника, затем для каждого поля ищет метод validate<Поле>()
 * и выполняет его. Всё происходит сразу при создании объекта:
 *
 *   $config = new ConfigTransfer($rawConfig);
 *   $config->compiler->brackets; // уже заполнено и провалидировано
 *
 * Маппинг:
 *  - Ключи массива сопоставляются со свойствами по имени ('brackets')
 *    или в snake_case ('dependency_injection' => $dependencyInjection).
 *  - Несовпадение типа свойства и значения не вызывает TypeError наружу,
 *    а превращается в обычную ошибку валидации.
 *  - Если свойство типизировано другим наследником BasicTransfer, а в данных
 *    лежит массив — вложенный DTO собирается и валидируется автоматически.
 *    Пути ошибок получают префикс ('compiler.brackets').
 *  - Отсутствующий ключ — ошибка, если у свойства нет значения по умолчанию.
 *  - Лишние ключи — ошибка (отключается: protected const bool ALLOW_EXTRA = true).
 *  - Свойства `private(set)` и `readonly` тоже заполняются.
 *
 * Валидация:
 *  - Метод называется validate + имя свойства с большой буквы: validateBrackets().
 *  - Метод получает Rule, уже привязанный к значению поля, и возвращает:
 *      Rule  — правило будет выполнено;
 *      true  — ручная проверка прошла;
 *      false — ручная проверка не прошла (общее сообщение).
 *    Всё остальное (в том числе забытый return) — LogicException.
 *    Можно и самому бросить ValidationException — тогда он уйдёт наружу сразу.
 *  - Все свойства к моменту валидации уже заполнены, поэтому метод может
 *    сверяться с соседними полями ($this->isCached ?? false — если поле
 *    могло не заполниться из-за ошибки).
 *  - Поля без метода validate проверяются только типом свойства.
 *  - Ошибки всех полей собираются вместе и бросаются одним ValidationException.
 */
abstract class BasicTransfer
{
    /** Разрешены ли в данных ключи, для которых нет свойства. */
    protected const bool ALLOW_EXTRA = false;

    /**
     * @param array<string, mixed> $data Данные для маппинга.
     * @param string               $path Префикс путей ошибок (для вложенных DTO), например 'compiler.'.
     *
     * @throws ValidationException
     */
    final public function __construct(array $data, string $path = '')
    {
        $errors = $this->hydrate($data, $path);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Маппинг + валидация. Ничего не бросает, а возвращает ошибки:
     * так вложенные DTO сливают свои ошибки с родительскими
     * без исключений.
     *
     * @return array<string, list<string>>
     * @throws \ReflectionException
     */
    private function hydrate(array $data, string $path): array
    {
        $reflection = new ReflectionClass($this);
        $properties = $this->mappableProperties($reflection);

        $errors = [];
        $failed = [];
        $labels = [];
        $used = [];

        // 1. Маппинг: заполняем все свойства до начала валидации.
        foreach ($properties as $name => $property) {
            $key = $this->findKey($data, $name);
            $label = $path . ($key ?? $this->snake($name));
            $labels[$name] = $label;

            if ($key === null) {
                if (!$property->isInitialized($this)) {
                    $errors[$label][] = sprintf('Обязательное поле «%s» отсутствует', $label);
                    $failed[$name] = true;
                }

                continue;
            }

            $used[$key] = true;
            $value = $data[$key];

            // Вложенный DTO: массив превращается в объект своего класса.
            $nested = $this->transferClass($property);

            if ($nested !== null && is_array($value)) {
                $child = (new ReflectionClass($nested))->newInstanceWithoutConstructor();
                $childErrors = $child->hydrate($value, $label . '.');

                $this->mergeErrors($errors, $childErrors);

                if ($childErrors !== []) {
                    $failed[$name] = true;
                }

                $value = $child;
            }

            try {
                $this->assign($property, $value);
            } catch (TypeError) {
                $errors[$label][] = sprintf(
                    'Поле «%s» должно быть типа %s, получено %s',
                    $label,
                    (string) ($property->getType() ?? 'mixed'),
                    get_debug_type($value)
                );
                $failed[$name] = true;
            }
        }

        if (!static::ALLOW_EXTRA) {
            foreach (array_keys(array_diff_key($data, $used)) as $extra) {
                $errors[$path . $extra][] = sprintf('Неизвестный ключ «%s%s»', $path, $extra);
            }
        }

        // 2. Валидация: по одному методу validate<Поле>() на поле.
        foreach ($this->validators($reflection, $properties) as $name => $method) {
            if (isset($failed[$name])) {
                continue; // значение не лежит в свойстве или уже невалидно
            }

            $label = $labels[$name];
            $result = $this->{$method}(Rule::of($this->{$name}, $label));

            if ($result instanceof Rule) {
                $this->mergeErrors($errors, $result->errors());
            } elseif ($result === false) {
                $errors[$label][] = sprintf('Поле «%s» не прошло валидацию', $label);
            } elseif ($result !== true) {
                throw new LogicException(sprintf(
                    '%s::%s() должен вернуть Rule или bool, получено %s',
                    static::class,
                    $method,
                    get_debug_type($result)
                ));
            }
        }

        return $errors;
    }

    /**
     * Публичные нестатические свойства наследника
     * (name => reflection).
     *
     * @return array<string, ReflectionProperty>
     */
    private function mappableProperties(ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $properties[$property->getName()] = $property;
            }
        }

        return $properties;
    }

    /**
     * Находит методы validate<Поле>() наследника:
     * поле => имя метода.
     *
     * @param array<string, ReflectionProperty> $properties
     * @return array<string, string>
     */
    private function validators(ReflectionClass $reflection, array $properties): array
    {
        $validators = [];

        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);

        foreach ($methods as $method) {
            $name = $method->getName();

            if ($method->isStatic()
                || $method->getDeclaringClass()->getName() === self::class
                || !str_starts_with($name, 'validate')
                || strlen($name) === 8
            ) {
                continue;
            }

            $property = lcfirst(substr($name, 8));

            // Опечатка в имени метода должна быть видна сразу, а не молча игнорироваться.
            if (!isset($properties[$property])) {
                throw new LogicException(sprintf(
                    '%s::%s() не соответствует ни одному публичному свойству (ожидалось $%s)',
                    static::class,
                    $name,
                    $property
                ));
            }

            $validators[$property] = $name;
        }

        return $validators;
    }

    /**
     * Класс вложенного DTO, если свойство типизировано
     * наследником BasicTransfer.
     *
     * @return class-string<BasicTransfer>|null
     */
    private function transferClass(ReflectionProperty $property): ?string
    {
        $type = $property->getType();

        if ($type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && is_subclass_of($type->getName(), self::class)
        ) {
            return $type->getName();
        }

        return null;
    }

    /**
     * Ищет ключ данных для свойства: 'dependencyInjection'
     * или 'dependency_injection'.
     */
    private function findKey(array $data, string $property): ?string
    {
        if (array_key_exists($property, $data)) {
            return $property;
        }

        $snake = $this->snake($property);

        return array_key_exists($snake, $data) ? $snake : null;
    }

    private function snake(string $property): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, list<string>> $more
     */
    private function mergeErrors(array &$errors, array $more): void
    {
        foreach ($more as $path => $messages) {
            foreach ($messages as $message) {
                $errors[$path][] = $message;
            }
        }
    }

    /**
     * Записывает значение в свойство от имени класса,
     * где оно объявлено.
     *
     * Так работают и `private(set)`, и `readonly`.
     * Strict types этого файла гарантируют отсутствие
     * тихих приведений типов.
     */
    private function assign(ReflectionProperty $property, mixed $value): void
    {
        $name = $property->getName();

        Closure::bind(
            function () use ($name, $value): void {
                $this->{$name} = $value;
            },
            $this,
            $property->getDeclaringClass()->getName()
        )();
    }
}