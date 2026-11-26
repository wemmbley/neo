<?php

declare(strict_types=1);

namespace Neo\Information\Binary\IO;

use Neo\Information\Binary\Exception\DecodeException;

final class BinaryReader
{
    private int $offset = 0;

    public function __construct(private readonly string $data) {}

    public function offset(): int { return $this->offset; }
    public function length(): int { return strlen($this->data); }
    public function remaining(): int { return $this->length() - $this->offset; }

    public function read(int $length): string
    {
        if ($length < 0 || $length > $this->remaining()) {
            throw new DecodeException("Unexpected end of packet at offset {$this->offset}; requested {$length} bytes, {$this->remaining()} available.");
        }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    public function skip(int $length): void { $this->read($length); }

    public function readCString(): string
    {
        $end = strpos($this->data, "\0", $this->offset);
        if ($end === false) {
            throw new DecodeException("Null terminator not found at offset {$this->offset}.");
        }
        $value = substr($this->data, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;
        return $value;
    }

    public function readUnsigned(int $bytes, string $endian): int
    {
        $raw = $this->read($bytes);
        $format = match ([$bytes, $endian]) {
            [1, 'little'], [1, 'big'] => 'C',
            [2, 'little'] => 'v', [2, 'big'] => 'n',
            [4, 'little'] => 'V', [4, 'big'] => 'N',
            default => null,
        };
        if ($format === null) {
            if ($bytes === 8) {
                $parts = unpack($endian === 'little' ? 'Vlow/Vhigh' : 'Nhigh/Nlow', $raw);
                $high = $parts[$endian === 'little' ? 'high' : 'high'];
                $low = $parts[$endian === 'little' ? 'low' : 'low'];
                if ($high > 0x7fffffff) {
                    throw new DecodeException('uint64 exceeds the supported PHP integer range.');
                }
                return ($high << 32) | $low;
            }
            throw new DecodeException("Unsupported unsigned integer width: {$bytes}.");
        }
        return (int) unpack($format, $raw)[1];
    }

    public function readSigned(int $bytes, string $endian): int
    {
        if ($bytes === 8) {
            $raw = $this->read(8);
            return (int) unpack('q', $endian === 'little' ? $raw : strrev($raw))[1];
        }
        $value = $this->readUnsigned($bytes, $endian);
        $bits = $bytes * 8;
        if ($value & (1 << ($bits - 1))) $value -= (1 << $bits);
        return $value;
    }

    public function readFloat(int $bytes, string $endian): float
    {
        $format = match ([$bytes, $endian]) {
            [4, 'little'] => 'g', [4, 'big'] => 'G',
            [8, 'little'] => 'e', [8, 'big'] => 'E',
            default => throw new DecodeException("Unsupported float width: {$bytes}."),
        };
        return (float) unpack($format, $this->read($bytes))[1];
    }

    public function readVarInt(): int
    {
        $result = 0;
        for ($i = 0; $i < 5; $i++) {
            $byte = $this->readUnsigned(1, 'little');
            $result |= ($byte & 0x7f) << (7 * $i);
            if (($byte & 0x80) === 0) return $result;
        }
        throw new DecodeException('VarInt exceeds 5 bytes.');
    }
}
