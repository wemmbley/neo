<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Pool;

use InvalidArgumentException;

/** Параметры пула (Runtime Specification, «Connection Pool»). Время в секундах. */
final class PoolOptions
{
    public function __construct(
        public readonly int $min = 0,
        public readonly int $max = 10,
        public readonly float $acquireTimeout = 3.0,
        public readonly float $idleTimeout = 60.0,
        public readonly float $maxLifetime = 3600.0,
        /** Если ресурс простаивал дольше, перед выдачей он проверяется (ping). */
        public readonly float $validateAfter = 30.0,
        /** Как часто фоновая чистка закрывает простаивающие ресурсы. 0 = не запускать. */
        public readonly float $reapInterval = 30.0,
    ) {
        if ($max < 1 || $min < 0 || $min > $max) {
            throw new InvalidArgumentException("Некорректные размеры пула: min={$min}, max={$max}.");
        }
    }

    /** @param array<string, mixed> $config ключи: min, max, acquire_timeout, idle_timeout, max_lifetime, validate_after, reap_interval */
    public static function fromArray(array $config): self
    {
        $default = new self();

        return new self(
            min: (int) ($config['min'] ?? $default->min),
            max: (int) ($config['max'] ?? $default->max),
            acquireTimeout: (float) ($config['acquire_timeout'] ?? $default->acquireTimeout),
            idleTimeout: (float) ($config['idle_timeout'] ?? $default->idleTimeout),
            maxLifetime: (float) ($config['max_lifetime'] ?? $default->maxLifetime),
            validateAfter: (float) ($config['validate_after'] ?? $default->validateAfter),
            reapInterval: (float) ($config['reap_interval'] ?? $default->reapInterval),
        );
    }
}
