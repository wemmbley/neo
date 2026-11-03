<?php

declare(strict_types=1);

namespace Neo\Validator;

use Closure;

/**
 * Одиночное правило валидации (одно значение, любая вложенность).
 *
 * Простой пример:
 *
 *   Rule::of($value, 'name')->string()->min(3)->max(255)->validate();
 *
 * Валидация конфига:
 *
 *   Rule::of($config, 'config')->array()->associative()->shape([
 *       'basic_path' => fn (Rule $r) => $r->string()->directory(),
 *       'is_cached'  => fn (Rule $r) => $r->bool(),
 *       'cache_path?' => fn (Rule $r) => $r->string()->writable(), // '?' = ключ необязателен
 *   ])->validate();
 *
 * Принципы:
 *  - Вызов нескольких типов (`->string()->int()`) означает объединение: string ИЛИ int.
 *  - Сначала проверяется тип. Если он не подошёл — остальные проверки не запускаются.
 *  - Проверки выполняются по порядку и останавливаются на первой упавшей
 *    (как `bail` в Laravel), поэтому поздние проверки могут не бояться неверных данных.
 *    Исключение: shape/keys/values собирают ошибки по ВСЕМ вложенным элементам сразу.
 *  - Ошибки возвращаются в формате ['путь.к.полю' => ['сообщение', ...]].
 */
final class Rule
{
    /** @var list<string> Допустимые типы (объединение). */
    private array $types = [];

    /** Разрешено ли значение null. */
    private bool $nullable = false;

    /** @var Closure Очередь проверок. */
    private array $checks = [];

    /** @var array<string, list<string>> Ошибки последнего прогона. */
    private array $errors = [];

    /**
     * @param mixed  $data Проверяемое значение.
     * @param string $path Имя значения в сообщениях об ошибках (для вложенных — 'compiler.brackets').
     */
    public function __construct(
        private readonly mixed $data,
        private readonly string $path = 'value'
    ) {
        parent::__construct();
    }

    public static function of(mixed $data, string $name = 'value'): self
    {
        return new self($data, $name);
    }

    #----------------------------------------------
    # Типы
    #----------------------------------------------

    /**
     * Произвольные типы: встроенные ('string', 'numeric', 'scalar', 'mixed'...)
     * или имена классов/интерфейсов (проверяется instanceof).
     */
    public function type(string ...$types): self
    {
        foreach ($types as $type) {
            if (!in_array($type, $this->types, true)) {
                $this->types[] = $type;
            }
        }

        return $this;
    }

    public function array(): self    { return $this->type('array'); }
    public function string(): self   { return $this->type('string'); }
    public function bool(): self     { return $this->type('bool'); }
    public function float(): self    { return $this->type('float'); }
    public function int(): self      { return $this->type('int'); }
    public function object(): self   { return $this->type('object'); }
    public function callable(): self { return $this->type('callable'); }
    public function resource(): self { return $this->type('resource'); }
    public function numeric(): self  { return $this->type('numeric'); }
    public function scalar(): self   { return $this->type('scalar'); }

    /**
     * Разрешает null (проверки не запускаются, если значение === null).
     */
    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    #----------------------------------------------
    # Общие
    #----------------------------------------------

    /**
     * Значение должно входить в список (строгое сравнение).
     */
    public function in(array $allowed): self
    {
        return $this->check(function (mixed $v) use ($allowed): void {
            if (!in_array($v, $allowed, true)) {
                $list = array_map(
                    static fn (mixed $a): string => is_scalar($a) ? var_export($a, true) : get_debug_type($a),
                    $allowed
                );
                $this->reject('должно быть одним из: %s', implode(', ', $list));
            }
        });
    }

    /**
     * Не пустая строка и не пустой массив (0 и false считаются заполненными).
     */
    public function notEmpty(): self
    {
        return $this->check(function (mixed $v): void {
            if ($v === '' || $v === []) {
                $this->reject('не должно быть пустым');
            }
        });
    }

    /**
     * Своя проверка. Сигнатура как в Validator: fn ($attribute, $value, $fail).
     * В сообщении $fail допустим плейсхолдер :attribute.
     *
     * @param Closure(string, mixed, Closure(string): void): mixed $callback
     */
    public function custom(Closure $callback): self
    {
        return $this->check(function (mixed $v) use ($callback): void {
            $fail = function (string $message): void {
                $this->errors[$this->path][] = str_replace(':attribute', $this->path, $message);
            };

            $callback($this->path, $v, $fail);
        });
    }

    #----------------------------------------------
    # Размер (число — значение, строка — длина, массив — count)
    #----------------------------------------------

    public function min(int|float $min): self
    {
        return $this->check(function (mixed $v) use ($min): void {
            $size = $this->sizeOf($v);

            if ($size !== null && $size < $min) {
                $this->reject('должно %s не менее %s%s', $this->verb($v), $min, $this->unit($v));
            }
        });
    }

    public function max(int|float $max): self
    {
        return $this->check(function (mixed $v) use ($max): void {
            $size = $this->sizeOf($v);

            if ($size !== null && $size > $max) {
                $this->reject('должно %s не более %s%s', $this->verb($v), $max, $this->unit($v));
            }
        });
    }

    /**
     * Точный размер: длина строки или количество элементов массива.
     */
    public function size(int $size): self
    {
        return $this->check(function (mixed $v) use ($size): void {
            $actual = $this->sizeOf($v);

            if ($actual !== null && $actual != $size) {
                $this->reject('должно %s ровно %s%s', $this->verb($v), $size, $this->unit($v));
            }
        });
    }

    #----------------------------------------------
    # Строки
    #----------------------------------------------

    public function regex(string $pattern): self
    {
        return $this->check(function (mixed $v) use ($pattern): void {
            if ($this->isString($v) && preg_match($pattern, $v) !== 1) {
                $this->reject('имеет неверный формат');
            }
        });
    }

    #----------------------------------------------
    # Классы
    #----------------------------------------------

    /**
     * Строка — имя существующего класса, интерфейса или enum (запускает автозагрузку).
     */
    public function classString(): self
    {
        return $this->check(function (mixed $v): void {
            if ($this->isString($v)
                && !class_exists($v) && !interface_exists($v) && !enum_exists($v)
            ) {
                $this->reject('ссылается на несуществующий класс или интерфейс (%s)', $v);
            }
        });
    }

    /**
     * Объект или имя класса, наследующее/реализующее $class.
     */
    public function instanceOf(string $class): self
    {
        return $this->check(function (mixed $v) use ($class): void {
            if (!(is_object($v) || is_string($v)) || !is_a($v, $class, true)) {
                $this->reject('должно быть наследником/реализацией %s', $class);
            }
        });
    }

    #----------------------------------------------
    # Файловая система
    #----------------------------------------------

    public function directory(): self
    {
        return $this->check(function (mixed $v): void {
            if ($this->isString($v) && !is_dir($v)) {
                $this->reject('указывает на несуществующую директорию (%s)', $v);
            }
        });
    }

    public function file(): self
    {
        return $this->check(function (mixed $v): void {
            if ($this->isString($v) && !is_file($v)) {
                $this->reject('указывает на несуществующий файл (%s)', $v);
            }
        });
    }

    /**
     * Путь доступен для записи. Если пути ещё нет (кэш создастся позже) —
     * проверяется ближайший существующий родитель.
     */
    public function writable(): self
    {
        return $this->check(function (mixed $v): void {
            if (!$this->isString($v)) {
                return;
            }

            $path = $v;

            while (!file_exists($path) && ($parent = dirname($path)) !== $path) {
                $path = $parent;
            }

            if (!is_writable($path)) {
                $this->reject('недоступно для записи (%s)', $v);
            }
        });
    }

    #----------------------------------------------
    # Массивы
    #----------------------------------------------

    /**
     * Ассоциативный массив (пустой тоже подходит).
     */
    public function associative(): self
    {
        $this->ensureArray();

        return $this->check(function (mixed $v): void {
            if (is_array($v) && $v !== [] && array_is_list($v)) {
                $this->reject('должно быть ассоциативным массивом');
            }
        });
    }

    /**
     * Список: ключи 0..n-1 по порядку (пустой тоже подходит).
     */
    public function list(): self
    {
        $this->ensureArray();

        return $this->check(function (mixed $v): void {
            if (is_array($v) && !array_is_list($v)) {
                $this->reject('должно быть списком (последовательным массивом)');
            }
        });
    }

    /**
     * Проверка КЛЮЧЕЙ массива.
     *
     * @param string|list<string>|Closure(self): mixed $keys Тип(ы) или closure с правилом для ключа.
     */
    public function keys(string|array|Closure $keys): self
    {
        $this->ensureArray();

        return $this->check(function (mixed $v) use ($keys): void {
            if (!is_array($v)) {
                return;
            }

            foreach (array_keys($v) as $key) {
                $this->nested($key, "{$this->path}.{$key} (ключ)", $keys);
            }
        });
    }

    /**
     * Проверка ЗНАЧЕНИЙ массива.
     *
     * @param string|list<string>|Closure(self): mixed $values Тип(ы) или closure с правилом для значения.
     */
    public function values(string|array|Closure $values): self
    {
        $this->ensureArray();

        return $this->check(function (mixed $v) use ($values): void {
            if (!is_array($v)) {
                return;
            }

            foreach ($v as $key => $value) {
                $this->nested($value, "{$this->path}.{$key}", $values);
            }
        });
    }

    /**
     * Схема ассоциативного массива: ключ => тип(ы) | closure(Rule).
     * Ключ с суффиксом '?' необязателен ('cache_path?').
     * Лишние ключи — ошибка, если не передан $allowExtra = true.
     *
     * @param array<string, string|list<string>|Closure(self): mixed> $schema
     */
    public function shape(array $schema, bool $allowExtra = false): self
    {
        $this->ensureArray();

        return $this->check(function (mixed $v) use ($schema, $allowExtra): void {
            if (!is_array($v)) {
                return;
            }

            $known = [];

            foreach ($schema as $key => $definition) {
                $key = (string) $key;
                $optional = str_ends_with($key, '?');
                $name = $optional ? substr($key, 0, -1) : $key;
                $known[$name] = true;

                if (!array_key_exists($name, $v)) {
                    if (!$optional) {
                        $this->errors["{$this->path}.{$name}"][] =
                            sprintf('Обязательное поле «%s.%s» отсутствует', $this->path, $name);
                    }

                    continue;
                }

                $this->nested($v[$name], "{$this->path}.{$name}", $definition);
            }

            if (!$allowExtra) {
                foreach (array_keys(array_diff_key($v, $known)) as $extra) {
                    $this->errors["{$this->path}.{$extra}"][] =
                        sprintf('Неизвестный ключ «%s.%s»', $this->path, $extra);
                }
            }
        });
    }

    #----------------------------------------------
    # Заключение
    #----------------------------------------------

    /**
     * Выполняет проверки и возвращает ошибки ([] если всё валидно).
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $this->errors = [];

        if ($this->data === null && $this->nullable) {
            return [];
        }

        if ($this->types !== [] && !$this->matchesTypes($this->data)) {
            $this->reject(
                'должно быть типа %s, получено %s',
                implode('|', $this->types),
                get_debug_type($this->data)
            );

            return $this->errors;
        }

        foreach ($this->checks as $check) {
            $check($this->data);

            if ($this->errors !== []) {
                break;
            }
        }

        return $this->errors;
    }

    public function fails(): bool
    {
        return $this->errors() !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /**
     * Возвращает исходное значение или бросает исключение.
     *
     * @throws ValidationException
     */
    public function validate(): mixed
    {
        $errors = $this->errors();

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->data;
    }

    #----------------------------------------------
    # Внутреннее
    #----------------------------------------------

    private function check(Closure $check): self
    {
        $this->checks[] = $check;

        return $this;
    }

    /**
     * Методы для массивов неявно требуют array, если тип не задан.
     */
    private function ensureArray(): void
    {
        if ($this->types === []) {
            $this->types = ['array'];
        }
    }

    /**
     * Запускает вложенное правило и сливает его ошибки в текущие.
     *
     * @param string|list<string>|Closure(self): mixed $definition
     */
    private function nested(mixed $value, string $path, string|array|Closure $definition): void
    {
        $child = new self($value, $path);

        if ($definition instanceof Closure) {
            $definition($child);
        } else {
            $child->type(...(array) $definition);
        }

        foreach ($child->errors() as $errorPath => $messages) {
            foreach ($messages as $message) {
                $this->errors[$errorPath][] = $message;
            }
        }
    }

    private function matchesTypes(mixed $value): bool
    {
        foreach ($this->types as $type) {
            $ok = match ($type) {
                'array'    => is_array($value),
                'string'   => is_string($value),
                'bool'     => is_bool($value),
                'float'    => is_float($value),
                'int'      => is_int($value),
                'object'   => is_object($value),
                'callable' => is_callable($value),
                'resource' => is_resource($value),
                'null'     => $value === null,
                'numeric'  => is_numeric($value),
                'scalar'   => is_scalar($value),
                'mixed'    => true,
                default    => $value instanceof $type,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function isString(mixed $value): bool
    {
        if (is_string($value)) {
            return true;
        }

        $this->reject('должно быть строкой, получено %s', get_debug_type($value));

        return false;
    }

    private function sizeOf(mixed $value): int|float|null
    {
        $size = match (true) {
            is_int($value), is_float($value) => $value,
            is_string($value)                => mb_strlen($value),
            is_array($value)                 => count($value),
            default                          => null,
        };

        if ($size === null) {
            $this->reject('имеет тип %s, для которого размер не определён', get_debug_type($value));
        }

        return $size;
    }

    private function verb(mixed $value): string
    {
        return is_string($value) || is_array($value) ? 'содержать' : 'быть';
    }

    private function unit(mixed $value): string
    {
        return match (true) {
            is_string($value) => ' символов',
            is_array($value)  => ' элементов',
            default           => '',
        };
    }

    /**
     * Добавляет ошибку для текущего пути: «Поле «path» <сообщение>».
     */
    private function reject(string $format, string|int|float ...$args): void
    {
        $this->errors[$this->path][] = sprintf('Поле «%s» ' . $format, $this->path, ...$args);
    }
}