<?php

declare(strict_types=1);

/**
 * Заглушка Swoole для тестов без расширения. Корутина здесь выполняется сразу, во вложенном вызове:
 * этого достаточно, чтобы проверить изоляцию контекстов, родителей, defer и пулы.
 * Подключается только если настоящего расширения нет.
 */

namespace Swoole {
    use ArrayObject;

    class Coroutine
    {
        /** @var list<int> */
        public static array $stack = [];
        public static int $seq = 0;
        /** @var array<int, ArrayObject> */
        public static array $contexts = [];
        /** @var array<int, int> */
        public static array $parents = [];
        /** @var array<int, list<callable>> */
        public static array $defers = [];

        public static function getCid(): int
        {
            return self::$stack === [] ? -1 : end(self::$stack);
        }

        public static function getPcid(int $cid = 0): int|false
        {
            return self::$parents[$cid] ?? false;
        }

        public static function getContext(?int $cid = null): ?ArrayObject
        {
            return self::$contexts[$cid ?? self::getCid()] ?? null;
        }

        /** Тест может «зависнуть»: корутина создана, но результата не будет. */
        public static bool $hang = false;

        public static function create(callable $fn): int
        {
            if (self::$hang) {
                return ++self::$seq;
            }

            $cid = ++self::$seq;
            self::$parents[$cid] = self::getCid();
            self::$contexts[$cid] = new ArrayObject();
            self::$defers[$cid] = [];
            self::$stack[] = $cid;

            try {
                $fn();
            } finally {
                foreach (array_reverse(self::$defers[$cid]) as $defer) {
                    $defer();
                }
                array_pop(self::$stack);
                unset(self::$contexts[$cid], self::$defers[$cid]);
            }

            return $cid;
        }

        public static function defer(callable $fn): void
        {
            self::$defers[self::getCid()][] = $fn;
        }

        public static function exists(int $cid): bool
        {
            return in_array($cid, self::$stack, true);
        }

        public static function cancel(int $cid): bool
        {
            return true;
        }

        public static function sleep(float $s): void {}
    }
}

namespace Swoole {
    class Timer
    {
        /** @var array<int, callable> */
        public static array $ticks = [];
        private static int $seq = 0;

        public static function tick(int $ms, callable $fn): int
        {
            self::$ticks[++self::$seq] = $fn;
            return self::$seq;
        }

        public static function clear(int $id): bool
        {
            unset(self::$ticks[$id]);
            return true;
        }
    }
}

namespace Swoole\Coroutine {
    use SplQueue;

    class Channel
    {
        private SplQueue $q;
        public function __construct(private int $capacity = 1) { $this->q = new SplQueue(); }
        public function push(mixed $v, float $t = -1): bool
        {
            if ($this->q->count() >= $this->capacity) { return false; }
            $this->q->enqueue($v);
            return true;
        }
        public function pop(float $t = -1): mixed { return $this->q->isEmpty() ? false : $this->q->dequeue(); }
        public function length(): int { return $this->q->count(); }
        public function isEmpty(): bool { return $this->q->isEmpty(); }
        public function close(): void {}
    }
}

namespace {
    foreach (['SWOOLE_HOOK_ALL' => 0xFFFF, 'SWOOLE_HOOK_FILE' => 0x1, 'SWOOLE_HOOK_SQLITE' => 0x2, 'SWOOLE_HOOK_TCP' => 0x4] as $name => $value) {
        if (!defined($name)) { define($name, $value); }
    }
}
