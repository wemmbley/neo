<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use InvalidArgumentException;

/**
 * Заголовки HTTP: имена регистронезависимы, значений может быть несколько.
 *
 * В «строгом» режиме (заголовки ответа) имя и значение проверяются: CR, LF и NUL
 * запрещены, это защита от HTTP response splitting / header injection.
 * Входящие заголовки приходят от сервера уже разобранными и не проверяются.
 */
final class HeaderBag
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @var array<string, string> нормализованное имя => имя как задали */
    private array $names = [];

    /** @param array<string, string|list<string>> $headers */
    public function __construct(array $headers = [], private readonly bool $strict = false)
    {
        foreach ($headers as $name => $value) {
            $this->set((string) $name, $value);
        }
    }

    public function set(string $name, string|int|array|null $value, bool $replace = true): void
    {
        $key = $this->normalize($name);

        if (is_string($value) || is_int($value)) {
            # Одно значение (почти всегда): без array_map и замыкания.
            $values = [$this->cleanValue((string) $value)];
        } else {
            $values = array_values(array_map(fn ($v): string => $this->cleanValue((string) $v), (array) ($value ?? '')));
        }

        if ($replace || !isset($this->headers[$key])) {
            $this->headers[$key] = $values;
        } else {
            array_push($this->headers[$key], ...$values);
        }

        $this->names[$key] ??= $this->cleanName($name);
    }

    public function add(string $name, string|int $value): void
    {
        $this->set($name, (string) $value, false);
    }

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->headers[$this->normalize($name)][0] ?? $default;
    }

    /** @return list<string> */
    public function all(string $name): array
    {
        return $this->headers[$this->normalize($name)] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->headers[$this->normalize($name)]);
    }

    public function remove(string $name): void
    {
        $key = $this->normalize($name);
        unset($this->headers[$key], $this->names[$key]);
    }

    /**
     * Содержит ли заголовок значение (список через запятую, без учёта регистра).
     */
    public function contains(string $name, string $value): bool
    {
        foreach ($this->all($name) as $line) {
            foreach (explode(',', $line) as $part) {
                if (strcasecmp(trim($part), $value) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, list<string>> имя как задали => значения */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->headers as $key => $values) {
            $result[$this->names[$key]] = $values;
        }

        return $result;
    }

    public function count(): int
    {
        return count($this->headers);
    }

    private function normalize(string $name): string
    {
        return strtolower($name);
    }

    /** @var array<string, true> имена, уже прошедшие проверку (их десятки, живут весь процесс) */
    private static array $validNames = [];

    private function cleanName(string $name): string
    {
        if ($this->strict && !isset(self::$validNames[$name])) {
            if (preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/', $name) !== 1) {
                throw new InvalidArgumentException("Недопустимое имя HTTP-заголовка [{$name}].");
            }

            self::$validNames[$name] = true;
        }

        return $name;
    }

    private function cleanValue(string $value): string
    {
        if ($this->strict && preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Значение HTTP-заголовка не должно содержать CR, LF или NUL.');
        }

        return $value;
    }
}
