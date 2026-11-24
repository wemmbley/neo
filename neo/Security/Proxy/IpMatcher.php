<?php

declare(strict_types=1);

namespace Neo\Security\Proxy;

/**
 * Сравнение IP с адресами и подсетями: `10.0.0.1`, `10.0.0.0/8`, `fd00::/8`, `*`.
 */
final class IpMatcher
{
    /** @param list<string>|string $ranges */
    public static function matches(string $ip, array|string $ranges): bool
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ((array) $ranges as $range) {
            $range = trim((string) $range);

            if ($range === '*' || $range === '**') {
                return true;
            }

            if ($range !== '' && self::matchesOne($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesOne(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            $a = @inet_pton($ip);
            $b = @inet_pton($range);

            return $a !== false && $b !== false && $a === $b;
        }

        [$subnet, $bits] = explode('/', $range, 2);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || !ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits > strlen($ipBin) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
