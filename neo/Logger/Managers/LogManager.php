<?php

declare(strict_types=1);

namespace Neo\Logger\Managers;

use Closure;
use InvalidArgumentException;
use Neo\Logger\Channels\FileReportChannel;
use Neo\Logger\Channels\ReportChannelContract;
use Neo\Logger\Channels\TelegramReportChannel;
use Neo\Logger\Drivers\Basic\ReportDriverContract;
use Neo\Logger\Drivers\DailyReportDriver;
use Neo\Logger\Drivers\DisableReportingReportDriver;
use Neo\Logger\Drivers\MonthlyReportDriver;
use Neo\Logger\Drivers\SingleReportDriver;
use Neo\Logger\Drivers\StackReportDriver;
use Neo\Logger\Transfers\LoggerTransfer;
use Throwable;

/**
 * Менеджер логирования.
 *
 * Создаёт и кеширует драйверы и каналы по конфигурации LoggerTransfer.
 * Сам реализует ReportDriverContract: вызовы Log::error() и т.п.
 * делегируются драйверу по умолчанию.
 *
 * Формат конфигурации:
 *
 *   'default'  => 'daily',
 *   'drivers'  => [
 *       'single'  => ['driver' => 'single',   'path' => '/var/log/app.log'],
 *       'daily'   => ['driver' => 'daily',    'path' => '/var/log/app.log', 'level' => 'info'],
 *       'all'     => ['driver' => 'stack',    'drivers' => ['single', 'daily']],
 *       'off'     => ['driver' => 'disabled'],
 *   ],
 *   'channels' => [
 *       'file'     => ['channel' => 'file', 'driver' => 'daily'],
 *       'telegram' => ['channel' => 'telegram', 'token' => '...', 'chat_id' => '...'],
 *   ],
 */
class LogManager implements ReportDriverContract
{
    /** Встроенные файловые драйверы, которым обязателен `path`. */
    protected const FILE_DRIVERS = [
        'single'  => SingleReportDriver::class,
        'daily'   => DailyReportDriver::class,
        'monthly' => MonthlyReportDriver::class,
    ];

    /** @var array<string, ReportDriverContract> Кеш созданных драйверов. */
    protected array $drivers = [];

    /** @var array<string, ReportChannelContract> Кеш созданных каналов. */
    protected array $channels = [];

    /** @var array<string, Closure> Пользовательские фабрики драйверов. */
    protected array $driverFactories = [];

    /** @var array<string, Closure> Пользовательские фабрики каналов. */
    protected array $channelFactories = [];

    /** @var string[] Стек имён, которые резолвятся сейчас (защита от циклов в stack). */
    protected array $resolving = [];

    public function __construct(protected LoggerTransfer $config) {}

    // ------------------------------------------------------------------
    // Драйверы
    // ------------------------------------------------------------------

    /**
     * Возвращает драйвер по имени из config->drivers (по умолчанию — config->default).
     */
    public function driver(?string $name = null): ReportDriverContract
    {
        $name ??= $this->config->default;

        return $this->drivers[$name] ??= $this->resolveDriver($name);
    }

    /**
     * Собирает драйвер на лету, не регистрируя его в конфигурации.
     *
     * @param array<string, mixed> $config
     */
    public function build(array $config): ReportDriverContract
    {
        $type = $config['driver'] ?? null;

        if (!is_string($type) || $type === '') {
            throw new InvalidArgumentException("Log driver config must contain a non-empty 'driver' key.");
        }

        if (isset($this->driverFactories[$type])) {
            return ($this->driverFactories[$type])($config, $this);
        }

        if (isset(self::FILE_DRIVERS[$type])) {
            $this->assertPath($config, $type);
            $class = self::FILE_DRIVERS[$type];

            return new $class($config);
        }

        return match ($type) {
            'stack'    => $this->buildStack($config),
            'disabled' => new DisableReportingReportDriver($config),
            default    => throw new InvalidArgumentException("Unsupported log driver type: {$type}"),
        };
    }

    /**
     * Временный стек из уже настроенных драйверов: Log::stack(['single', 'daily'])->info(...).
     *
     * @param string[]             $names
     * @param array<string, mixed> $config Доп. настройки стека (например, level).
     */
    public function stack(array $names, array $config = []): ReportDriverContract
    {
        return $this->build($config + ['driver' => 'stack', 'drivers' => $names]);
    }

    /**
     * Регистрирует собственный тип драйвера.
     * Фабрика получает (array $config, LogManager $manager) и возвращает ReportDriverContract.
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->driverFactories[$driver] = $factory;
        unset($this->drivers[$driver]);

        return $this;
    }

    // ------------------------------------------------------------------
    // Каналы (отправка исключений)
    // ------------------------------------------------------------------

    /**
     * Возвращает канал по имени из config->channels.
     */
    public function channel(string $name): ReportChannelContract
    {
        return $this->channels[$name] ??= $this->resolveChannel($name);
    }

    /**
     * Регистрирует собственный тип канала.
     * Фабрика получает (array $config, LogManager $manager).
     */
    public function extendChannel(string $type, Closure $factory): static
    {
        $this->channelFactories[$type] = $factory;
        $this->channels = [];

        return $this;
    }

    /**
     * Отправляет исключение в каналы (по умолчанию во все настроенные).
     *
     * Сбой одного канала не должен ломать приложение или скрывать исходную ошибку,
     * поэтому внутренние исключения каналов гасятся.
     *
     * @param string[]|null $channels Имена каналов или null для всех.
     */
    public function report(Throwable $exception, ?array $channels = null): void
    {
        foreach ($channels ?? array_keys($this->config->channels) as $name) {
            try {
                $this->channel($name)->send($exception);
            } catch (Throwable) {
                // Репортёр не имеет права падать сам.
            }
        }
    }

    // ------------------------------------------------------------------
    // ReportDriverContract: делегирование драйверу по умолчанию
    // ------------------------------------------------------------------

    public function emergency(string $message, array $context = []): void
    {
        $this->driver()->emergency($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->driver()->alert($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->driver()->critical($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->driver()->error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->driver()->warning($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->driver()->notice($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->driver()->info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->driver()->debug($message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->driver()->log($level, $message, $context);
    }

    // ------------------------------------------------------------------
    // Внутренняя кухня
    // ------------------------------------------------------------------

    protected function resolveDriver(string $name): ReportDriverContract
    {
        $config = $this->config->drivers[$name] ?? null;

        if (!is_array($config)) {
            throw new InvalidArgumentException("Log driver [{$name}] is not configured.");
        }

        if (in_array($name, $this->resolving, true)) {
            throw new InvalidArgumentException(
                'Circular log stack: ' . implode(' -> ', [...$this->resolving, $name])
            );
        }

        $this->resolving[] = $name;

        try {
            return $this->build($config);
        } finally {
            array_pop($this->resolving);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function buildStack(array $config): StackReportDriver
    {
        $names = $config['drivers'] ?? [];

        if (!is_array($names) || $names === []) {
            throw new InvalidArgumentException("Stack log driver requires a non-empty 'drivers' list.");
        }

        $drivers = array_map(fn (string $name) => $this->driver($name), array_values($names));

        return new StackReportDriver($config, $drivers);
    }

    protected function resolveChannel(string $name): ReportChannelContract
    {
        $config = $this->config->channels[$name] ?? null;

        if (!is_array($config)) {
            throw new InvalidArgumentException("Log channel [{$name}] is not configured.");
        }

        $type = $config['channel'] ?? $name;

        if (isset($this->channelFactories[$type])) {
            return ($this->channelFactories[$type])($config, $this);
        }

        return match ($type) {
            'file'     => new FileReportChannel($this->driver($config['driver'] ?? null), $config),
            'telegram' => new TelegramReportChannel($config),
            default    => throw new InvalidArgumentException("Unsupported log channel type: {$type}"),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function assertPath(array $config, string $type): void
    {
        if (!isset($config['path']) || !is_string($config['path']) || $config['path'] === '') {
            throw new InvalidArgumentException("Log driver [{$type}] requires a non-empty 'path'.");
        }
    }
}