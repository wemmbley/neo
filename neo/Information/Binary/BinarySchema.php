<?php

declare(strict_types=1);

namespace Neo\Information\Binary;

use Neo\Information\Binary\Exception\BinaryException;

/** Immutable compiled representation of a Datagram definition. */
final class BinarySchema
{
    /** @param list<array<string,mixed>> $fields */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly string $endian = 'little',
    ) {
        if (!in_array($endian, ['little', 'big'], true)) {
            throw new BinaryException("Unknown byte order '{$endian}'.");
        }
    }
}
