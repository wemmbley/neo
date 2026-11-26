<?php

declare(strict_types=1);

namespace Neo\Information\Binary;

use Neo\Information\Binary\Exception\EncodeException;
use Neo\Information\Binary\IO\BinaryWriter;

final class BinaryEncoder
{
    public function encode(BinarySchema $schema, array $data): string
    {
        $writer = new BinaryWriter();
        $this->encodeFields($schema->fields, $writer, $data, $schema->endian);
        return $writer->result();
    }

    /** @param list<array<string,mixed>> $fields @param array<string,mixed> $context */
    private function encodeFields(array $fields, BinaryWriter $writer, array $context, string $defaultEndian): void
    {
        foreach ($fields as $field) {
            $kind = $field['kind'];
            $name = $field['name'] ?? null;
            $endian = $field['endian'] ?? $defaultEndian;
            $value = $name !== null ? ($context[$name] ?? $field['value'] ?? null) : null;
            switch ($kind) {
                case 'constant': $writer->write($field['value']); break;
                case 'bytes':
                    $value ??= $field['value'];
                    if (!is_string($value) || strlen($value) !== $field['length']) throw new EncodeException("Field '{$name}' must contain exactly {$field['length']} bytes.");
                    $writer->write($value);
                    break;
                case 'skip': $writer->write(str_repeat("\0", $field['length'])); break;
                case 'remaining':
                    if (!is_string($value)) throw new EncodeException("Field '{$name}' must be a byte string.");
                    $writer->write($value);
                    break;
                case 'uint':
                    if ($field['value'] !== null && $value !== $field['value']) throw new EncodeException("Field '{$name}' must equal its declared constant.");
                    if (!is_int($value)) throw new EncodeException("Field '{$name}' must be an integer.");
                    $writer->writeUnsigned($value, $field['bytes'], $endian);
                    break;
                case 'int':
                    if ($field['value'] !== null && $value !== $field['value']) throw new EncodeException("Field '{$name}' must equal its declared constant.");
                    if (!is_int($value)) throw new EncodeException("Field '{$name}' must be an integer.");
                    $writer->writeSigned($value, $field['bytes'], $endian);
                    break;
                case 'float':
                    if (!is_int($value) && !is_float($value)) throw new EncodeException("Field '{$name}' must be numeric.");
                    $writer->writeFloat((float) $value, $field['bytes'], $endian);
                    break;
                case 'bool': $writer->writeUnsigned($value ? 1 : 0, 1, 'little'); break;
                case 'char':
                    if (!is_string($value) || strlen($value) !== 1) throw new EncodeException("Field '{$name}' must be exactly one byte.");
                    $writer->write($value);
                    break;
                case 'varint':
                    if (!is_int($value)) throw new EncodeException("Field '{$name}' must be an integer.");
                    $writer->writeVarInt($value);
                    break;
                case 'cstring':
                    if (!is_string($value)) throw new EncodeException("Field '{$name}' must be a string.");
                    $wire = $this->convertToWire($value, $field['encoding']);
                    if (str_contains($wire, "\0")) throw new EncodeException("Field '{$name}' cannot contain a null byte.");
                    $writer->write($wire . "\0");
                    break;
                case 'string':
                    if (!is_string($value)) throw new EncodeException("Field '{$name}' must be a string.");
                    $wire = $this->convertToWire($value, $field['encoding']);
                    $this->writeLength($field['length'], strlen($wire), $field['endian'], $writer);
                    $writer->write($wire);
                    break;
                case 'json':
                    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->writeLength($field['length'], strlen($json), $field['endian'], $writer);
                    $writer->write($json);
                    break;
                case 'delimited':
                    if (!is_array($value)) throw new EncodeException("Field '{$name}' must be an array.");
                    $joined = implode($field['delimiter'], array_map('strval', $value));
                    $this->writeLength($field['length'], strlen($joined), $field['endian'], $writer);
                    $writer->write($joined);
                    break;
                case 'enum':
                    $raw = is_int($value) ? $value : array_search($value, $field['values'], true);
                    if ($raw === false || $raw === null) throw new EncodeException("Unknown enum value for '{$name}'.");
                    $writer->writeUnsigned((int) $raw, $field['bytes'], $endian);
                    break;
                case 'bitmask':
                    if (!is_array($value)) throw new EncodeException("Field '{$name}' must be an array of flags.");
                    $raw = 0;
                    foreach ($field['bits'] as $flag => $mask) if (($value[$flag] ?? false) === true) $raw |= (int) $mask;
                    $writer->writeUnsigned($raw, $field['bytes'], $endian);
                    break;
                case 'repeat':
                    if (!is_array($value)) throw new EncodeException("Field '{$name}' must be an array.");
                    $expected = is_int($field['count']) ? $field['count'] : (int) ($context[$field['count']] ?? count($value));
                    if (count($value) !== $expected) throw new EncodeException("Field '{$name}' must contain {$expected} items; " . count($value) . ' given.');
                    foreach ($value as $item) {
                        if (!is_array($item)) throw new EncodeException("Each item in '{$name}' must be an array.");
                        $this->encodeFields($field['fields'], $writer, $item, $defaultEndian);
                    }
                    break;
                case 'when':
                    $actual = $context[$field['field']] ?? null;
                    $matches = $field['bit'] !== null
                        ? (((int) $actual & (int) $field['bit']) !== 0)
                        : ($actual === $field['equals'] || (string) $actual === (string) $field['equals']);
                    if ($matches) $this->encodeFields($field['fields'], $writer, $context, $defaultEndian);
                    break;
                case 'switch':
                    $actual = $context[$field['field']] ?? null;
                    $branch = $field['cases'][$actual] ?? $field['cases'][(string) $actual] ?? $field['default'];
                    if ($branch !== null) $this->encodeFields($branch, $writer, $context, $defaultEndian);
                    break;
                case 'align':
                    $padding = ($field['boundary'] - ($writer->length() % $field['boundary'])) % $field['boundary'];
                    if ($padding > 0) $writer->write(str_repeat(chr($field['padding']), $padding));
                    break;
                default: throw new EncodeException("Unknown schema field kind '{$kind}'.");
            }
        }
    }

    private function convertToWire(string $value, string $encoding): string
    {
        if ($encoding === 'binary' || strtoupper($encoding) === 'UTF-8') return $value;
        $converted = iconv('UTF-8', $encoding, $value);
        if ($converted === false) throw new EncodeException("Unable to convert string from UTF-8 to {$encoding}.");
        return $converted;
    }

    private function writeLength(int|string $definition, int $length, string $endian, BinaryWriter $writer): void
    {
        if (is_int($definition)) {
            if ($length !== $definition) throw new EncodeException("String must be exactly {$definition} bytes.");
            return;
        }
        match ($definition) {
            'uint8' => $writer->writeUnsigned($length, 1, $endian),
            'uint16' => $writer->writeUnsigned($length, 2, $endian),
            'uint32' => $writer->writeUnsigned($length, 4, $endian),
            'varint' => $writer->writeVarInt($length),
            default => throw new EncodeException("Unsupported length type '{$definition}'."),
        };
    }
}
