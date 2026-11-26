<?php

declare(strict_types=1);

namespace Neo\Information\Binary;

use Neo\Information\Binary\Exception\DecodeException;
use Neo\Information\Binary\IO\BinaryReader;

final class BinaryDecoder
{
    public function decode(BinarySchema $schema, string $packet): array
    {
        $reader = new BinaryReader($packet);
        $result = [];
        $this->decodeFields($schema->fields, $reader, $result, $schema->endian);
        if ($reader->remaining() !== 0) {
            throw new DecodeException("{$reader->remaining()} unread bytes remain after decoding '{$schema->name}'.");
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $fields @param array<string,mixed> $context */
    private function decodeFields(array $fields, BinaryReader $reader, array &$context, string $defaultEndian): void
    {
        foreach ($fields as $field) {
            $kind = $field['kind'];
            $name = $field['name'] ?? null;
            $endian = $field['endian'] ?? $defaultEndian;
            switch ($kind) {
                case 'constant':
                    $actual = $reader->read(strlen($field['value']));
                    if ($actual !== $field['value']) throw new DecodeException("Constant '{$name}' does not match.");
                    if ($name !== null) $context[$name] = $actual;
                    break;
                case 'bytes':
                    $value = $reader->read($field['length']);
                    if ($field['value'] !== null && $value !== $field['value']) throw new DecodeException("Bytes field '{$name}' does not match its expected value.");
                    $context[$name] = $value;
                    break;
                case 'skip': $reader->skip($field['length']); break;
                case 'remaining': $context[$name] = $reader->read($reader->remaining()); break;
                case 'uint':
                    $value = $reader->readUnsigned($field['bytes'], $endian);
                    if ($field['value'] !== null && $value !== $field['value']) throw new DecodeException("Field '{$name}' has unexpected value.");
                    $context[$name] = $value;
                    break;
                case 'int':
                    $value = $reader->readSigned($field['bytes'], $endian);
                    if ($field['value'] !== null && $value !== $field['value']) throw new DecodeException("Field '{$name}' has unexpected value.");
                    $context[$name] = $value;
                    break;
                case 'float': $context[$name] = $reader->readFloat($field['bytes'], $endian); break;
                case 'bool': $context[$name] = $reader->readUnsigned(1, 'little') !== 0; break;
                case 'char': $context[$name] = $reader->read(1); break;
                case 'varint': $context[$name] = $reader->readVarInt(); break;
                case 'cstring': $context[$name] = $this->convertFromWire($reader->readCString(), $field['encoding']); break;
                case 'string':
                    $length = $this->readLength($field['length'], $field['endian'], $reader);
                    $context[$name] = $this->convertFromWire($reader->read($length), $field['encoding']);
                    break;
                case 'json':
                    $length = $this->readLength($field['length'], $field['endian'], $reader);
                    $json = $reader->read($length);
                    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    $context[$name] = $decoded;
                    break;
                case 'delimited':
                    $length = $this->readLength($field['length'], $field['endian'], $reader);
                    $value = $reader->read($length);
                    $context[$name] = $value === '' ? [] : explode($field['delimiter'], $value);
                    break;
                case 'enum':
                    $raw = $reader->readUnsigned($field['bytes'], $endian);
                    $context[$name . '_raw'] = $raw;
                    $context[$name] = $field['values'][$raw] ?? (string) $raw;
                    break;
                case 'bitmask':
                    $raw = $reader->readUnsigned($field['bytes'], $endian);
                    $flags = [];
                    foreach ($field['bits'] as $flag => $mask) $flags[$flag] = (($raw & (int) $mask) === (int) $mask);
                    $context[$name] = $flags;
                    $context[$name . '_raw'] = $raw;
                    break;
                case 'repeat':
                    $count = is_int($field['count']) ? $field['count'] : (int) ($context[$field['count']] ?? throw new DecodeException("Repeat count '{$field['count']}' is unavailable."));
                    if ($count < 0 || $count > 100000) throw new DecodeException("Invalid repeat count {$count}.");
                    $items = [];
                    for ($i = 0; $i < $count; $i++) {
                        $item = [];
                        $this->decodeFields($field['fields'], $reader, $item, $defaultEndian);
                        $items[] = $item;
                    }
                    $context[$name] = $items;
                    break;
                case 'when':
                    $actual = $context[$field['field']] ?? null;
                    $matches = $field['bit'] !== null
                        ? (((int) $actual & (int) $field['bit']) !== 0)
                        : ($actual === $field['equals'] || (string) $actual === (string) $field['equals']);
                    if ($matches) $this->decodeFields($field['fields'], $reader, $context, $defaultEndian);
                    break;
                case 'switch':
                    $actual = $context[$field['field']] ?? null;
                    $branch = $field['cases'][$actual] ?? $field['cases'][(string) $actual] ?? $field['default'];
                    if ($branch !== null) $this->decodeFields($branch, $reader, $context, $defaultEndian);
                    break;
                case 'align':
                    $padding = ($field['boundary'] - ($reader->offset() % $field['boundary'])) % $field['boundary'];
                    if ($padding > 0) {
                        $actual = $reader->read($padding);
                        if ($actual !== str_repeat(chr($field['padding']), $padding)) throw new DecodeException('Invalid alignment padding.');
                    }
                    break;
                default: throw new DecodeException("Unknown schema field kind '{$kind}'.");
            }
        }
    }

    private function convertFromWire(string $value, string $encoding): string
    {
        if ($encoding === 'binary' || strtoupper($encoding) === 'UTF-8') return $value;
        $converted = iconv($encoding, 'UTF-8', $value);
        if ($converted === false) throw new DecodeException("Unable to convert string from {$encoding} to UTF-8.");
        return $converted;
    }

    private function readLength(int|string $definition, string $endian, BinaryReader $reader): int
    {
        if (is_int($definition)) return $definition;
        return match ($definition) {
            'uint8' => $reader->readUnsigned(1, $endian),
            'uint16' => $reader->readUnsigned(2, $endian),
            'uint32' => $reader->readUnsigned(4, $endian),
            'varint' => $reader->readVarInt(),
            default => throw new DecodeException("Unsupported length type '{$definition}'."),
        };
    }
}
