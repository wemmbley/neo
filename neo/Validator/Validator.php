<?php

declare(strict_types=1);

namespace Neo\Validator;

use Neo\Support\DotNotation;

/**
 * Проверка данных по правилам.
 *
 *   $v = Validator::make($data, [
 *       'name'         => 'required|string|max:255',
 *       'email'        => ['required', 'email'],
 *       'stock'        => 'required|integer|min:0',
 *       'tags.*'       => 'string',                  // для каждого элемента массива
 *       'code'         => ['required', fn ($attribute, $value, $fail) => $value === 'x' || $fail(':attribute неверен')],
 *   ]);
 *
 *   $v->fails();                 // bool
 *   $v->errors();                // ['email' => ['Поле email должно быть ...']]  (формат для @error)
 *   $clean = $v->validate();     // только поля с правилами; при ошибке ValidationException
 *
 * Правила: required, present, filled, accepted, nullable, sometimes, bail, string, integer, numeric, boolean,
 * array, email, url, ip, json, uuid, date, date_format:Y-m-d, alpha, alpha_num, alpha_dash, min:n, max:n, size:n,
 * between:a,b, digits:n, in:a,b, not_in:a,b, same:поле, different:поле, confirmed, regex:/шаблон/,
 * starts_with:a,b, ends_with:a,b.
 *
 * Правила `min`, `max`, `size`, `between` смотрят на тип: для чисел это значение (если есть `integer` или
 * `numeric`), для строк количество символов, для массивов количество элементов.
 *
 * Пустые необязательные поля пропускаются: проверки идут только для заполненных (кроме required, present,
 * filled, accepted). `nullable` разрешает null, `bail` останавливает проверку поля на первой ошибке.
 *
 * Тексты ошибок берутся из языкового файла (см. {@see self::LANG}), порядок поиска такой:
 * `поле.правило` и `поле.*.правило` из $messages, затем `правило` из $messages, затем текст правила,
 * добавленного через {@see extend()}, затем языковой файл.
 *
 * Свои правила: {@see extend()} (например, `unique` и `exists` подключает слой базы данных).
 */
final class Validator
{
    /**
     * Префикс ключей сообщений в языковом процессоре: `{LANG}.required`, `{LANG}.min.numeric` и т.д.
     * Запасной текст для правил без сообщения лежит по ключу `{LANG}.invalid`.
     */
    private const LANG = 'validation::validation';

    /** Правила, которые выполняются, даже если поля нет или оно пустое. */
    private const IMPLICIT = ['required', 'present', 'filled', 'accepted'];

    private const META = ['bail', 'nullable', 'sometimes'];

    /** @var array<string, callable> */
    private static array $extensions = [];

    /** @var array<string, string> */
    private static array $extensionMessages = [];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, list<array{0: string, 1: list<string>, 2: ?\Closure}>>|null */
    private ?array $expanded = null;

    private bool $ran = false;

    /**
     * @param array<string, mixed>                 $data
     * @param array<string, string|list<mixed>>    $rules
     * @param array<string, string>                $messages   Свои тексты: `'поле.правило'` или `'правило'`.
     * @param array<string, string>                $attributes Читаемые названия полей: `['email' => 'почта']`.
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly array $attributes = [],
    ) {
        parent::__construct();
    }

    /**
     * @param array<string, mixed>              $data
     * @param array<string, string|list<mixed>> $rules
     * @param array<string, string>             $messages
     * @param array<string, string>             $attributes
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): self
    {
        return new self($data, $rules, $messages, $attributes);
    }

    /**
     * Добавляет своё правило, например `unique`.
     *
     * @param callable(string, mixed, list<string>, array<string, mixed>): bool $callback Получает имя поля, значение,
     *                                                                                      параметры правила и все данные.
     * @param string|null $message Текст ошибки с плейсхолдерами `:attribute`.
     */
    public static function extend(string $rule, callable $callback, ?string $message = null): void
    {
        self::$extensions[$rule] = $callback;

        if ($message !== null) {
            self::$extensionMessages[$rule] = $message;
        }
    }

    /**
     * Удаляет все добавленные правила (нужно в тестах).
     */
    public static function flushExtensions(): void
    {
        self::$extensions = [];
        self::$extensionMessages = [];
    }

    public function passes(): bool
    {
        return $this->errors() === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Ошибки в виде `['поле' => ['сообщение', ...]]`: тот же формат читает `@error`.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $this->run();

        return $this->errors;
    }

    /**
     * Проверяет данные и возвращает только те поля, для которых заданы правила.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException Если данные не прошли проверку.
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        $result = [];

        foreach (array_keys($this->expand()) as $attribute) {
            if (DotNotation::has($this->data, $attribute)) {
                DotNotation::set($result, $attribute, DotNotation::get($this->data, $attribute));
            }
        }

        return $result;
    }

    /**
     * То же, что {@see validate()}.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validate();
    }

    // ------------------------------------------------------------------

    private function run(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        foreach ($this->expand() as $attribute => $rules) {
            $this->validateAttribute($attribute, $rules);
        }
    }

    /**
     * Раскрывает `items.*.name` в конкретные поля и разбирает правила.
     *
     * @return array<string, list<array{0: string, 1: list<string>, 2: ?\Closure}>>
     */
    private function expand(): array
    {
        if ($this->expanded !== null) {
            return $this->expanded;
        }

        $expanded = [];

        foreach ($this->rules as $pattern => $rules) {
            $parsed = $this->parseRules($rules);

            if (str_contains($pattern, '*')) {
                foreach (DotNotation::expandWildcard($this->data, $pattern) as $path) {
                    $expanded[$path] = $parsed;
                }
            } else {
                $expanded[$pattern] = $parsed;
            }
        }

        return $this->expanded = $expanded;
    }

    /**
     * @param string|list<mixed> $rules
     *
     * @return list<array{0: string, 1: list<string>, 2: ?\Closure}>
     */
    private function parseRules(string|array $rules): array
    {
        $parsed = [];

        foreach (is_string($rules) ? explode('|', $rules) : $rules as $rule) {
            if ($rule instanceof \Closure) {
                $parsed[] = ['closure', [], $rule];
                continue;
            }

            if (!is_string($rule)) {
                throw new \InvalidArgumentException('Правило должно быть строкой или замыканием.');
            }

            if ($rule === '') {
                continue;
            }

            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
            // Шаблон regex может содержать запятые и «|», поэтому не режем его на параметры.
            $params = $argument === null ? [] : ($name === 'regex' ? [$argument] : explode(',', $argument));
            $parsed[] = [$name, $params, null];
        }

        return $parsed;
    }

    /**
     * @param list<array{0: string, 1: list<string>, 2: ?\Closure}> $rules
     */
    private function validateAttribute(string $attribute, array $rules): void
    {
        $present = DotNotation::has($this->data, $attribute);
        $value = DotNotation::get($this->data, $attribute);
        $names = array_column($rules, 0);
        $bail = in_array('bail', $names, true);
        $nullable = in_array('nullable', $names, true);
        $hasNumeric = array_intersect($names, ['numeric', 'integer']) !== [];

        if (in_array('sometimes', $names, true) && !$present) {
            return;
        }

        foreach ($rules as [$name, $params, $closure]) {
            if (in_array($name, self::META, true)) {
                continue;
            }

            if ($closure === null && !in_array($name, self::IMPLICIT, true)) {
                if (!$present || (is_string($value) && trim($value) === '') || ($value === null && $nullable)) {
                    continue;
                }
            }

            if ($closure !== null) {
                $failed = false;
                $fail = function (string $message) use (&$failed, $attribute): void {
                    $failed = true;
                    $this->errors[$attribute][] = strtr($message, [':attribute' => $this->displayName($attribute)]);
                };

                $closure($attribute, $value, $fail);

                if ($failed && $bail) {
                    break;
                }

                continue;
            }

            if ($this->check($name, $params, $attribute, $value, $present, $hasNumeric)) {
                continue;
            }

            $this->errors[$attribute][] = $this->message($attribute, $name, $params, $value, $hasNumeric);

            if ($bail) {
                break;
            }
        }
    }

    /**
     * @param list<string> $params
     */
    private function check(string $rule, array $params, string $attribute, mixed $value, bool $present, bool $hasNumeric): bool
    {
        if (isset(self::$extensions[$rule])) {
            return (bool) (self::$extensions[$rule])($attribute, $value, $params, $this->data);
        }

        return match ($rule) {
            'required' => $present && !$this->isEmpty($value),
            'present' => $present,
            'filled' => !$present || !$this->isEmpty($value),
            'accepted' => in_array($value, ['yes', 'on', '1', 1, true, 'true'], true),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'numeric' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => in_array($value, [true, false, 1, 0, '1', '0'], true),
            'array' => is_array($value),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value) === 1
                && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'json' => is_string($value) && $this->isJson($value),
            'uuid' => is_string($value)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            'date' => is_string($value) && $this->isDate($value),
            'date_format' => is_string($value) && $this->matchesDateFormat($value, $params[0] ?? ''),
            'alpha' => is_string($value) && preg_match('/^\pL+$/u', $value) === 1,
            'alpha_num' => is_string($value) && preg_match('/^[\pL\pN]+$/u', $value) === 1,
            'alpha_dash' => is_string($value) && preg_match('/^[\pL\pN_-]+$/u', $value) === 1,
            'min' => $this->size($value, $hasNumeric) >= $this->number($rule, $params, 0),
            'max' => $this->size($value, $hasNumeric) <= $this->number($rule, $params, 0),
            'size' => $this->size($value, $hasNumeric) == $this->number($rule, $params, 0),
            'between' => $this->size($value, $hasNumeric) >= $this->number($rule, $params, 0)
                && $this->size($value, $hasNumeric) <= $this->number($rule, $params, 1),
            'digits' => is_scalar($value) && ctype_digit((string) $value)
                && strlen((string) $value) === (int) $this->number($rule, $params, 0),
            'in' => is_scalar($value) && in_array((string) $value, $params, true),
            'not_in' => !is_scalar($value) || !in_array((string) $value, $params, true),
            'same' => DotNotation::get($this->data, $this->field($rule, $params)) === $value,
            'different' => DotNotation::get($this->data, $this->field($rule, $params)) !== $value,
            'confirmed' => DotNotation::get($this->data, $attribute . '_confirmation') === $value,
            'regex' => $this->matchesRegex($value, $params[0] ?? ''),
            'starts_with' => is_string($value) && $this->anyOf($params, static fn (string $p): bool => str_starts_with($value, $p)),
            'ends_with' => is_string($value) && $this->anyOf($params, static fn (string $p): bool => str_ends_with($value, $p)),
            default => throw new \InvalidArgumentException("Неизвестное правило валидации «{$rule}»."),
        };
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    private function isJson(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Похоже ли значение на дату. Одного strtotime() мало: он принимает «x» (военный часовой пояс),
     * «now» и «tomorrow», поэтому требуем цифру и отсутствие ошибок разбора.
     */
    private function isDate(string $value): bool
    {
        if (preg_match('/\d/', $value) !== 1 || strtotime($value) === false) {
            return false;
        }

        $parsed = date_parse($value);

        return $parsed['error_count'] === 0;
    }

    private function matchesDateFormat(string $value, string $format): bool
    {
        if ($format === '') {
            throw new \InvalidArgumentException('Правило date_format требует формат: date_format:Y-m-d.');
        }

        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value;
    }

    private function matchesRegex(mixed $value, string $pattern): bool
    {
        if ($pattern === '') {
            throw new \InvalidArgumentException('Правило regex требует шаблон: regex:/^\d+$/.');
        }

        if (!is_scalar($value)) {
            return false;
        }

        $result = @preg_match($pattern, (string) $value);

        if ($result === false) {
            throw new \InvalidArgumentException("Некорректный шаблон regex: {$pattern}.");
        }

        return $result === 1;
    }

    /**
     * @param list<string> $items
     */
    private function anyOf(array $items, \Closure $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Размер значения: число для чисел, количество символов для строк, количество элементов для массивов.
     */
    private function size(mixed $value, bool $hasNumeric): int|float
    {
        return match (true) {
            is_array($value) => count($value),
            is_int($value), is_float($value) => $value,
            is_string($value) && $hasNumeric && is_numeric($value) => $value + 0,
            is_string($value) => (int) preg_match_all('/./su', $value),
            default => 0,
        };
    }

    /**
     * @param list<string> $params
     */
    private function number(string $rule, array $params, int $index): int|float
    {
        if (!isset($params[$index]) || !is_numeric($params[$index])) {
            throw new \InvalidArgumentException("Правило {$rule} требует числовой параметр, например {$rule}:5.");
        }

        return $params[$index] + 0;
    }

    /**
     * Имя поля из параметров правила (same:поле, different:поле).
     * Пустой ключ в dot-нотации означает «весь массив», поэтому пропущенный параметр это ошибка.
     *
     * @param list<string> $params
     */
    private function field(string $rule, array $params): string
    {
        if (($params[0] ?? '') === '') {
            throw new \InvalidArgumentException("Правило {$rule} требует имя поля, например {$rule}:password.");
        }

        return $params[0];
    }

    /**
     * @param list<string> $params
     */
    private function message(string $attribute, string $rule, array $params, mixed $value, bool $hasNumeric): string
    {
        $wildcard = (string) preg_replace('/\.\d+(?=\.|$)/', '.*', $attribute);
        $template = $this->messages[$attribute . '.' . $rule]
            ?? $this->messages[$wildcard . '.' . $rule]
            ?? $this->messages[$rule]
            ?? self::$extensionMessages[$rule]
            ?? $this->langMessage($rule);

        // Для min/max/size/between в языковом файле лежит группа: numeric, string, array.
        if (is_array($template)) {
            $type = match (true) {
                is_array($value) => 'array',
                is_int($value), is_float($value) => 'numeric',
                is_string($value) && $hasNumeric && is_numeric($value) => 'numeric',
                default => 'string',
            };
            $template = $template[$type];
        }

        $first = $params[0] ?? '';
        $replace = [':attribute' => $this->displayName($attribute)];

        $replace += match ($rule) {
            'min' => [':min' => $first],
            'max' => [':max' => $first],
            'size' => [':size' => $first],
            'between' => [':min' => $first, ':max' => $params[1] ?? ''],
            'digits' => [':digits' => $first],
            'same', 'different' => [':other' => $this->displayName($first)],
            'in', 'not_in', 'starts_with', 'ends_with' => [':values' => implode(', ', $params)],
            'date_format' => [':format' => $first],
            default => [],
        };

        return strtr($template, $replace);
    }

    /**
     * Сообщение правила из языкового файла: строка или группа (для min/max/size/between).
     * Языковой процессор возвращает сам ключ, если перевода нет, тогда берём запасной текст.
     *
     * @return string|array<string, string>
     */
    private function langMessage(string $rule): string|array
    {
        $key = self::LANG . '.' . $rule;
        $line = __($key);

        return $line === $key ? __(self::LANG . '.invalid') : $line;
    }

    private function displayName(string $attribute): string
    {
        return $this->attributes[$attribute] ?? str_replace('_', ' ', $attribute);
    }
}