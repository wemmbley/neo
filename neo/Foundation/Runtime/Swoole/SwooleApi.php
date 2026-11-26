<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use ArrayAccess;
use RuntimeException;

/**
 * Единая точка доступа к Swoole и OpenSwoole. У них одинаковые идеи и почти одинаковые классы,
 * но разные пространства имён (`Swoole\*` и `OpenSwoole\*`), поэтому остальной код
 * не пишет `\Swoole\...` напрямую, а ходит сюда.
 *
 * Классы определяются по факту загрузки (class_exists без автозагрузки), а не по extension_loaded:
 * так адаптер работает и с обычным расширением, и с заглушками в тестах.
 */
final class SwooleApi
{
    /** @var 'Swoole'|'OpenSwoole'|null */
    private static ?string $namespace = null;

    public static function available(): bool
    {
        return self::detect() !== null;
    }

    /** Расширение загружено, но код выполняется не в корутине (master-процесс, обычный PHP). */
    public static function inCoroutine(): bool
    {
        return self::detect() !== null && self::cid() > 0;
    }

    /** Имя вида «Swoole» или «OpenSwoole»: для сообщений и логов. */
    public static function flavor(): string
    {
        return self::ns();
    }

    /** Полное имя класса: `SwooleApi::cls('Http\Server')` → `\Swoole\Http\Server` / `\OpenSwoole\Http\Server`. */
    public static function cls(string $name): string
    {
        return '\\' . self::ns() . '\\' . $name;
    }

    public static function cid(): int
    {
        $class = self::cls('Coroutine');

        return (int) $class::getCid();
    }

    public static function pcid(int $cid): int
    {
        $class = self::cls('Coroutine');

        return (int) ($class::getPcid($cid) ?: 0);
    }

    /** Контекст корутины (ArrayObject) или null, если корутины нет. */
    public static function context(?int $cid = null): ?ArrayAccess
    {
        $class = self::cls('Coroutine');
        $context = $cid === null ? $class::getContext() : $class::getContext($cid);

        return $context instanceof ArrayAccess ? $context : null;
    }

    public static function create(callable $callback): int
    {
        $class = self::cls('Coroutine');
        $cid = $class::create($callback);

        return $cid === false ? 0 : (int) $cid;
    }

    public static function defer(callable $callback): void
    {
        $class = self::cls('Coroutine');
        $class::defer($callback);
    }

    public static function cancel(int $cid): bool
    {
        $class = self::cls('Coroutine');

        return $class::exists($cid) && (bool) $class::cancel($cid);
    }

    public static function sleep(float $seconds): void
    {
        $class = self::cls('Coroutine');
        $class::sleep($seconds);
    }

    public static function tick(float $seconds, callable $callback): int
    {
        $class = self::cls('Timer');

        return (int) $class::tick(max(1, (int) round($seconds * 1000)), $callback);
    }

    public static function clearTimer(int $timerId): void
    {
        $class = self::cls('Timer');
        $class::clear($timerId);
    }

    public static function channel(int $capacity): object
    {
        $class = self::cls('Coroutine\Channel');

        return new $class(max(1, $capacity));
    }

    /** Константа расширения: `SwooleApi::constant('HOOK_ALL')` → SWOOLE_HOOK_ALL или OPENSWOOLE_HOOK_ALL. */
    /** Необязательная константа: если её нет (старая сборка, тестовая заглушка), берём значение по умолчанию. */
    public static function constantOr(string $name, int $default): int
    {
        try {
            return (int) self::constant($name);
        } catch (\RuntimeException) {
            return $default;
        }
    }

    public static function constant(string $name): int
    {
        foreach (['SWOOLE_', 'OPENSWOOLE_'] as $prefix) {
            if (defined($prefix . $name)) {
                return (int) constant($prefix . $name);
            }
        }

        throw new RuntimeException("Константа {$name} не найдена ни в Swoole, ни в OpenSwoole.");
    }

    public static function cpuNum(): int
    {
        if (function_exists('swoole_cpu_num')) {
            return max(1, (int) swoole_cpu_num());
        }

        $util = self::cls('Util');

        if (self::ns() === 'OpenSwoole' && method_exists($util, 'getCPUNum')) {
            return max(1, (int) $util::getCPUNum());
        }

        return 4;
    }

    /** Забывает определённый вариант (для тестов). */
    public static function forget(): void
    {
        self::$namespace = null;
    }

    private static function ns(): string
    {
        return self::detect() ?? throw new RuntimeException(
            'Не найдено расширение swoole или openswoole. Движок работает и без него: используйте обычный запуск.'
        );
    }

    private static function detect(): ?string
    {
        if (self::$namespace !== null) {
            return self::$namespace;
        }

        return match (true) {
            class_exists('Swoole\Coroutine', false) => self::$namespace = 'Swoole',
            class_exists('OpenSwoole\Coroutine', false) => self::$namespace = 'OpenSwoole',
            default => null,
        };
    }
}
