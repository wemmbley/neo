<?php

declare(strict_types=1);

namespace Neo\Information\Binary;

use Neo\Information\Binary\Exception\BinaryException;

/** Public fluent DSL. It builds a BinarySchema; it does not read or write bytes itself. */
final class Datagram
{
    /** @var array<string,BinarySchema> */
    private static array $registry = [];
    /** @var list<array<string,mixed>> */
    private array $fields = [];
    private string $defaultEndian = 'little';

    public function __construct(public readonly string $name) {}

    public static function register(self $datagram): void
    {
        self::$registry[$datagram->name] = $datagram->schema();
    }

    public static function registered(string $name): ?BinarySchema
    {
        return self::$registry[$name] ?? null;
    }

    public static function clearRegistry(): void { self::$registry = []; }

    public function endian(string $endian): self
    {
        $this->assertEndian($endian);
        $this->defaultEndian = $endian;
        return $this;
    }

    public function use(string|BinarySchema|self $schema): self
    {
        if ($schema instanceof self) $schema = $schema->schema();
        elseif (is_string($schema)) $schema = self::registered($schema)
            ?? throw new BinaryException("Datagram '{$schema}' is not registered.");
        foreach ($schema->fields as $field) $this->fields[] = $field;
        return $this;
    }

    /** Constant raw bytes. For hexadecimal input use constantHex(). */
    public function constant(string $name, string $value): self
    {
        $this->fields[] = ['kind' => 'constant', 'name' => $name, 'value' => $value];
        return $this;
    }

    public function constantHex(string $name, string $hex): self
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? $hex;
        if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            throw new BinaryException("Invalid hexadecimal constant '{$name}'.");
        }
        return $this->constant($name, hex2bin($hex));
    }

    public function bytes(string $name, int $length, ?string $value = null): self
    {
        $this->assertLength($length);
        $this->fields[] = ['kind' => 'bytes', 'name' => $name, 'length' => $length, 'value' => $value];
        return $this;
    }

    public function skip(int $length): self
    {
        $this->assertLength($length);
        $this->fields[] = ['kind' => 'skip', 'length' => $length];
        return $this;
    }

    public function remaining(string $name = 'remaining'): self
    {
        $this->fields[] = ['kind' => 'remaining', 'name' => $name];
        return $this;
    }

    public function uint8(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('uint', $name, 1, $value, $endian); }
    public function uint16(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('uint', $name, 2, $value, $endian); }
    public function uint32(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('uint', $name, 4, $value, $endian); }
    public function uint64(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('uint', $name, 8, $value, $endian); }
    public function int8(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('int', $name, 1, $value, $endian); }
    public function int16(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('int', $name, 2, $value, $endian); }
    public function int32(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('int', $name, 4, $value, $endian); }
    public function int64(string $name, ?int $value = null, ?string $endian = null): self { return $this->integer('int', $name, 8, $value, $endian); }

    public function float32(string $name, ?float $value = null, ?string $endian = null): self { return $this->floating($name, 4, $value, $endian); }
    public function float64(string $name, ?float $value = null, ?string $endian = null): self { return $this->floating($name, 8, $value, $endian); }
    public function float(string $name, ?float $value = null, ?string $endian = null): self { return $this->float32($name, $value, $endian); }
    public function double(string $name, ?float $value = null, ?string $endian = null): self { return $this->float64($name, $value, $endian); }

    public function boolean(string $name, ?bool $value = null): self
    {
        $this->fields[] = ['kind' => 'bool', 'name' => $name, 'value' => $value];
        return $this;
    }

    public function char(string $name, ?string $value = null): self
    {
        $this->fields[] = ['kind' => 'char', 'name' => $name, 'value' => $value];
        return $this;
    }

    /** Length can be an integer byte count or a prefix type: uint8/uint16/uint32/varint. */
    public function string(string $name, int|string $length = 'uint16', ?string $endian = null, string $encoding = 'binary'): self
    {
        if (is_int($length)) $this->assertLength($length);
        elseif (!in_array($length, ['uint8', 'uint16', 'uint32', 'varint'], true)) throw new BinaryException("Unsupported string length type '{$length}'.");
        $endian ??= $this->defaultEndian;
        $this->assertEndian($endian);
        $this->fields[] = ['kind' => 'string', 'name' => $name, 'length' => $length, 'endian' => $endian, 'encoding' => $encoding];
        return $this;
    }

    public function cstring(string $name, string $encoding = 'binary'): self
    {
        $this->fields[] = ['kind' => 'cstring', 'name' => $name, 'encoding' => $encoding];
        return $this;
    }

    /** @param array<int|string,string> $values Numeric wire value => symbolic name. */
    public function enum(string $name, int $bytes, array $values, string $endian = 'little'): self
    {
        if (!in_array($bytes, [1, 2, 4], true)) throw new BinaryException('Enum width must be 1, 2 or 4 bytes.');
        $this->fields[] = ['kind' => 'enum', 'name' => $name, 'bytes' => $bytes, 'values' => $values, 'endian' => $endian];
        return $this;
    }

    public function varint(string $name, ?int $value = null): self
    {
        $this->fields[] = ['kind' => 'varint', 'name' => $name, 'value' => $value];
        return $this;
    }

    /** Decode a delimited string into a list. Encoding writes a list joined by the delimiter. */
    public function delimited(string $name, string $delimiter, int|string $length = 'uint16', ?string $endian = null): self
    {
        $endian ??= $this->defaultEndian;
        $this->fields[] = ['kind' => 'delimited', 'name' => $name, 'delimiter' => $delimiter, 'length' => $length, 'endian' => $endian];
        return $this;
    }

    /** JSON is a length-prefixed UTF-8 string decoded into a PHP value. */
    public function json(string $name, int|string $length = 'uint32', ?string $endian = null): self
    {
        $endian ??= $this->defaultEndian;
        $this->fields[] = ['kind' => 'json', 'name' => $name, 'length' => $length, 'endian' => $endian];
        return $this;
    }

    /** @param array<string,int|string> $bits Map of output key => bit mask. */
    public function bitmask(string $name, int $bytes, array $bits, ?string $endian = null): self
    {
        $endian ??= $this->defaultEndian;
        if (!in_array($bytes, [1, 2, 4], true)) throw new BinaryException('Bitmask width must be 1, 2 or 4 bytes.');
        $this->fields[] = ['kind' => 'bitmask', 'name' => $name, 'bytes' => $bytes, 'bits' => $bits, 'endian' => $endian];
        return $this;
    }

    /** @param callable(self):void $datagram */
    public function array(string $name, int|string $count, callable $datagram): self
    {
        return $this->repeat($name, $count, $datagram);
    }

    public function repeat(string $name, int|string $count, callable $datagram): self
    {
        $nested = new self($this->name . '.' . $name);
        $nested->endian($this->defaultEndian);
        $datagram($nested);
        $this->fields[] = ['kind' => 'repeat', 'name' => $name, 'count' => $count, 'fields' => $nested->fields];
        return $this;
    }

    /** @param callable(self):void $datagram */
    public function when(string $field, callable $datagram, int|string|null $equals = null, ?int $bit = null): self
    {
        if ($equals === null && $bit === null) throw new BinaryException('when() requires equals or bit.');
        $nested = new self($this->name . '.when.' . $field);
        $nested->endian($this->defaultEndian);
        $datagram($nested);
        $this->fields[] = ['kind' => 'when', 'field' => $field, 'equals' => $equals, 'bit' => $bit, 'fields' => $nested->fields];
        return $this;
    }

    /** Select one nested structure using a previously decoded field. @param array<int|string,callable(self):void> $cases */
    public function switch(string $field, array $cases, ?callable $default = null): self
    {
        $compiled = [];
        foreach ($cases as $value => $callback) {
            $nested = new self($this->name . '.switch.' . $field . '.' . $value);
            $nested->endian($this->defaultEndian);
            $callback($nested);
            $compiled[$value] = $nested->fields;
        }
        $defaultFields = null;
        if ($default !== null) {
            $nested = new self($this->name . '.switch.' . $field . '.default');
            $nested->endian($this->defaultEndian);
            $default($nested);
            $defaultFields = $nested->fields;
        }
        $this->fields[] = ['kind' => 'switch', 'field' => $field, 'cases' => $compiled, 'default' => $defaultFields];
        return $this;
    }

    /** Align current packet offset to a multiple of $boundary, using $padding byte. */
    public function align(int $boundary, int $padding = 0): self
    {
        if ($boundary < 1 || $padding < 0 || $padding > 255) throw new BinaryException('Invalid alignment arguments.');
        $this->fields[] = ['kind' => 'align', 'boundary' => $boundary, 'padding' => $padding];
        return $this;
    }

    public function schema(): BinarySchema { return new BinarySchema($this->name, $this->fields, $this->defaultEndian); }
    public function decode(string $packet): array { return (new BinaryDecoder())->decode($this->schema(), $packet); }
    public function encode(array $data): string { return (new BinaryEncoder())->encode($this->schema(), $data); }

    private function integer(string $kind, string $name, int $bytes, ?int $value, ?string $endian): self
    {
        $endian ??= $this->defaultEndian;
        $this->assertEndian($endian);
        $this->fields[] = ['kind' => $kind, 'name' => $name, 'bytes' => $bytes, 'value' => $value, 'endian' => $endian];
        return $this;
    }

    private function floating(string $name, int $bytes, ?float $value, ?string $endian): self
    {
        $endian ??= $this->defaultEndian;
        $this->assertEndian($endian);
        $this->fields[] = ['kind' => 'float', 'name' => $name, 'bytes' => $bytes, 'value' => $value, 'endian' => $endian];
        return $this;
    }

    private function assertEndian(string $endian): void
    {
        if (!in_array($endian, ['little', 'big'], true)) throw new BinaryException("Unknown byte order '{$endian}'.");
    }
    private function assertLength(int $length): void
    {
        if ($length < 0) throw new BinaryException('Length cannot be negative.');
    }
}
