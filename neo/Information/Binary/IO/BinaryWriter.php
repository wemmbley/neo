<?php

declare(strict_types=1);

namespace Neo\Information\Binary\IO;

use Neo\Information\Binary\Exception\EncodeException;

final class BinaryWriter
{
    private string $data = '';
    public function write(string $bytes): void { $this->data .= $bytes; }
    public function length(): int { return strlen($this->data); }
    public function result(): string { return $this->data; }

    public function writeUnsigned(int $value, int $bytes, string $endian): void
    {
        if ($value < 0) throw new EncodeException('Unsigned integer cannot be negative.');
        $format = match ([$bytes, $endian]) {
            [1, 'little'], [1, 'big'] => 'C',
            [2, 'little'] => 'v', [2, 'big'] => 'n',
            [4, 'little'] => 'V', [4, 'big'] => 'N',
            default => null,
        };
        if ($format !== null) {
            $max = $bytes === 4 ? 0xffffffff : ((1 << ($bytes * 8)) - 1);
            if ($value > $max) throw new EncodeException("Value {$value} does not fit in uint" . ($bytes * 8) . ".");
            $this->write(pack($format, $value));
            return;
        }
        if ($bytes === 8) {
            $low = $value & 0xffffffff;
            $high = ($value >> 32) & 0xffffffff;
            $this->write($endian === 'little' ? pack('V2', $low, $high) : pack('N2', $high, $low));
            return;
        }
        throw new EncodeException("Unsupported unsigned integer width: {$bytes}.");
    }

    public function writeSigned(int $value, int $bytes, string $endian): void
    {
        $bits = $bytes * 8;
        if ($bytes === 8) {
            $raw = pack('q', $value);
            $this->write($endian === 'little' ? $raw : strrev($raw));
            return;
        }
        $min = -(1 << ($bits - 1));
        $max = (1 << ($bits - 1)) - 1;
        if ($value < $min || $value > $max) throw new EncodeException("Value {$value} does not fit in int{$bits}.");
        if ($value < 0) $value += (1 << $bits);
        $this->writeUnsigned($value, $bytes, $endian);
    }

    public function writeFloat(float $value, int $bytes, string $endian): void
    {
        $format = match ([$bytes, $endian]) {
            [4, 'little'] => 'g', [4, 'big'] => 'G',
            [8, 'little'] => 'e', [8, 'big'] => 'E',
            default => throw new EncodeException("Unsupported float width: {$bytes}."),
        };
        $this->write(pack($format, $value));
    }

    public function writeVarInt(int $value): void
    {
        if ($value < 0) throw new EncodeException('VarInt cannot be negative in this implementation.');
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            if ($value !== 0) $byte |= 0x80;
            $this->write(pack('C', $byte));
        } while ($value !== 0);
    }
}
