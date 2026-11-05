<?php

declare(strict_types=1);

namespace Neo\Storage\Managers;

use Neo\Storage\Contracts\DiskContract;
use Neo\Storage\Contracts\DriverContract;
use Neo\Storage\Contracts\StorageManagerContract;
use Neo\Storage\Domain\Disk;
use Neo\Storage\Domain\PathNormalizer;
use Neo\Storage\Drivers\LocalDriver;
use Neo\Storage\Drivers\MemoryDriver;
use Neo\Storage\Enums\Visibility;
use Neo\Storage\Exceptions\DiskNotConfiguredException;
use Neo\Storage\Exceptions\FileNotFoundException;
use Neo\Storage\Exceptions\UnableToWriteException;
use Neo\Storage\Transfers\StorageTransfer;

#
# new StorageManager(new StorageTransfer([
#       'default' => 'local',
#       'disks' => [
#           'local' => [
#               'driver' => 'local',
#               'root' => __DIR__ . '/myFolder',
#               'serve' => true,     // разрешены url()
#               'throw' => false,    // bool-методы возвращают false вместо исключения
#               'report' => false,   // отправлять сбои в репортёр (логгер)
#               'url' => '/storage', // необязательно
#               'visibility' => 'public', // необязательно; по умолчанию: serve ? public : private
#           ],
#       ],
#       'links' => ['/var/www/public/storage' => '/var/www/storage/app'],
#   ]), reporter: fn (StorageException $e, array $context) => $logger->error(...));
#
#   StorageTransfer - вложенный объект DTO.
#
class StorageManager implements StorageManagerContract
{
    /** @var array<string, DiskContract> уже собранные диски */
    private array $disks = [];

    /** @var array<string, \Closure(array): (DriverContract|DiskContract)> фабрики драйверов */
    private array $drivers = [];

    /**
     * @param \Closure(\Throwable, array): void|null $reporter куда отправлять сбои дисков с report=true
     */
    public function __construct(
        private readonly StorageTransfer $config,
        private readonly ?\Closure $reporter = null,
    ) {
        $this->drivers['local'] = static fn (array $disk): DriverContract => new LocalDriver(
            root: $disk['root'] ?? throw new DiskNotConfiguredException('Local disk requires the [root] option.'),
            url: $disk['url'] ?? '/storage',
            defaultVisibility: Visibility::resolve(
                $disk['visibility'] ?? null,
                ($disk['serve'] ?? false) ? Visibility::Public : Visibility::Private,
            ),
        );

        $this->drivers['memory'] = static fn (array $disk): DriverContract => new MemoryDriver();
    }

    /** null = диск по умолчанию. Собранные диски кешируются. */
    public function disk(?string $name = null): DiskContract
    {
        $name ??= $this->getDefaultDriver();

        return $this->disks[$name] ??= $this->make($name, $this->diskConfig($name));
    }

    /** Диск из конфига "на лету" (не кешируется и не регистрируется). */
    public function build(array $config): DiskContract
    {
        return $this->make('on-the-fly', $config);
    }

    /**
     * Регистрирует свой драйвер.
     * Фабрика получает массив конфига диска и возвращает DriverContract
     * (или уже готовый DiskContract, если нужен полный контроль).
     */
    public function extend(string $driver, \Closure $factory): void
    {
        $this->drivers[$driver] = $factory;
    }

    /** Подменяет диск in-memory копией (для тестов). Ошибки на фейке всегда бросаются. */
    public function fake(?string $disk = null): DiskContract
    {
        $name = $disk ?? $this->getDefaultDriver();

        return $this->disks[$name] = new Disk(
            driver: new MemoryDriver(),
            name: $name,
            throw: true,
            serve: true,
            manager: $this,
        );
    }

    /** Имя диска по умолчанию. */
    public function getDefaultDriver(): string
    {
        return $this->config->default;
    }

    /**
     * Создаёт симлинки из конфига links (['путь ссылки' => 'цель']).
     * Существующие реальные файлы и папки не трогает; существующие симлинки
     * пересоздаёт только при $force.
     *
     * @return array<string, string> созданные ссылки
     */
    public function link(bool $force = false): array
    {
        $created = [];

        foreach ($this->config->links as $link => $target) {
            if (is_link($link) || file_exists($link)) {
                if (!$force || !is_link($link)) {
                    continue;
                }

                PathNormalizer::removeLink($link);
            }

            if (!is_dir($target)) {
                throw new FileNotFoundException("Link target [{$target}] does not exist.");
            }

            if (!@symlink($target, $link)) {
                throw new UnableToWriteException("Unable to create the symlink [{$link}].");
            }

            $created[$link] = $target;
        }

        return $created;
    }

    /** Удаляет симлинк (файла или папки), не трогая цель. */
    public static function removeLink(string $path): bool
    {
        if (@unlink($path)) {
            return true;
        }

        // Windows: симлинк на папку удаляется только через rmdir
        return is_dir($path) && @rmdir($path);
    }

    // ---------------------------------------------------------------- внутреннее

    private function diskConfig(string $name): array
    {
        $disk = $this->config->disks[$name]
            ?? throw new DiskNotConfiguredException("Disk [{$name}] is not configured.");

        // Диск может прийти как массив или как вложенный DTO.
        return is_array($disk) ? $disk : get_object_vars($disk);
    }

    private function make(string $name, array $config): DiskContract
    {
        $driver = $config['driver'] ?? throw new DiskNotConfiguredException("Disk [{$name}] has no [driver].");
        $factory = $this->drivers[$driver]
            ?? throw new DiskNotConfiguredException("Driver [{$driver}] is not registered.");

        $made = $factory($config);

        if ($made instanceof DiskContract) {
            return $made;
        }

        return new Disk(
            driver: $made,
            name: $name,
            throw: (bool) ($config['throw'] ?? false),
            report: (bool) ($config['report'] ?? false),
            serve: (bool) ($config['serve'] ?? false),
            manager: $this,
            reporter: $this->reporter,
        );
    }
}