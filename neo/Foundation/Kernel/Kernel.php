<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

use LogicException;
use Neo\Foundation\Config\Config;
use Neo\Foundation\Kernel\Bootstrappers\ConfigBootstrapper;
use Neo\Foundation\Kernel\Bootstrappers\ConsoleBootstrapper;
use Neo\Foundation\Kernel\Bootstrappers\EnvBootstrapper;

/**
 * Это точка сборки всего семантического пространства Kernel:
 * Разворот контейнера, внедрение зависимостей, провайдеры.
 *
 * Порядок запуска:
 *
 *  1. new Kernel($container, $basePath)  контейнер + сам Kernel в нём;
 *  2. bootstrap()  строго по порядку: Env → Config → Console,
 *     затем регистрация провайдеров из config('app.providers');
 *  3. boot()/run()  boot() всех провайдеров.
 *
 * Жизненный цикл провайдера:
 *
 *  1. registerProvider() создаёт провайдера и сразу вызывает register(),
 *     поэтому привязки доступны ещё до boot().
 *  2. boot() вызывает boot() у всех зарегистрированных (один раз).
 *  3. Провайдер, добавленный после boot() (в том числе отложенный,
 *     загрузившийся по первому обращению), получает register() и boot() сразу.
 *
 * Ленивость: провайдер, реализующий DeferrableProviderContract и передаваемый
 * именем класса, даже не создаётся, пока кто-нибудь не запросит
 * один из его сервисов (см. DeferrableProviderContract::provides()).
 */
final class Kernel
{
    private static ?self $instance = null;

    /**
     * То, что передали в registerProvider(), как есть.
     *
     * @var list<ServiceProvider|class-string<ServiceProvider>>
     */
    private array $providers = [];

    /**
     * Созданные и зарегистрированные провайдеры в порядке регистрации.
     *
     * @var list<ServiceProvider>
     */
    private array $active = [];

    /**
     * Классы, уже заявленные строкой (защита от дублей).
     *
     * @var array<class-string, true>
     */
    private array $declared = [];

    /**
     * Провайдеры (spl_object_id), у которых boot() уже вызван.
     *
     * @var array<int, true>
     */
    private array $bootedIds = [];

    private bool $booted = false;

    private bool $bootstrapped = false;

    /**
     * Первые инъекции в контейнер, строго в этом порядке.
     * Env должен быть раньше Config (в конфигах вызывается env()),
     * Config раньше Console (список команд лежит в конфиге).
     *
     * @var list<class-string<BootstrapperContract>>
     */
    private const array BOOTSTRAPPERS = [
        EnvBootstrapper::class,
        ConfigBootstrapper::class,
        ConsoleBootstrapper::class,
    ];

    /**
     * Ресурсы, которые провайдеры заявляют ядру (роуты, вьюхи, миграции...).
     * Читать их будут соответствующие модули через resources().
     *
     * @var array<string, list<mixed>>
     */
    private array $resources = [];

    private readonly string $basePath;

    /**
     * @param list<ServiceProvider|class-string<ServiceProvider>> $providers
     */
    public function __construct(
        public readonly ServiceContainer $container,
        string $basePath,
        array $providers = [],
        private readonly ?string $envFile = null,
    ) {
        $this->basePath = rtrim($basePath, '/\\');
        self::$instance = $this;

        # Без этого контейнер при автосборке ServiceProvider(Kernel $app)
        # создал бы ВТОРОЕ приложение с новым контейнером.
        #
        $container->instance(self::class, $this);
        $container->instance(ServiceContainer::class, $container);

        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }

    /**
     * Выполняет бутстрапперы (Env → Config → Console) и регистрирует
     * провайдеры из `app.providers`. Повторный вызов ничего не делает.
     *
     * @param list<class-string<BootstrapperContract>>|null $bootstrappers
     */
    public function bootstrap(?array $bootstrappers = null): static
    {
        if ($this->bootstrapped) {
            return $this;
        }

        $this->bootstrapped = true;

        foreach ($bootstrappers ?? self::BOOTSTRAPPERS as $class) {
            $bootstrapper = $this->container->make($class);

            if (!$bootstrapper instanceof BootstrapperContract) {
                throw new LogicException(
                    "Бутстраппер {$class} должен реализовывать " . BootstrapperContract::class . '.'
                );
            }

            $bootstrapper->bootstrap($this);
        }

        if ($this->container->has(Config::class)) {
            /** @var Config $config */
            $config = $this->container->make(Config::class);

            foreach ((array) $config->get('app.providers', []) as $provider) {
                $this->registerProvider($provider);
            }
        }

        return $this;
    }

    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    /**
     * Корень проекта или путь внутри него.
     */
    public function basePath(string $path = ''): string
    {
        return $path === ''
            ? $this->basePath
            : $this->basePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    /**
     * Папка с файлами настроек.
     */
    public function configPath(string $path = ''): string
    {
        return $this->basePath('app' . DIRECTORY_SEPARATOR . 'Config')
            . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
    }

    /**
     * Провайдер заявляет ресурс (см. NeoServiceProviderTrait).
     */
    public function addResource(string $type, mixed $payload): void
    {
        $this->resources[$type][] = $payload;
    }

    /**
     * @return list<mixed>
     */
    public function resources(string $type): array
    {
        return $this->resources[$type] ?? [];
    }

    /**
     * Запускает жизненный цикл приложения (повторный вызов ничего не делает).
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        # count() каждый раз новый: провайдеры могут добавлять других
        # провайдеров прямо из register()/boot().
        #
        for ($i = 0; $i < count($this->active); $i++) {
            $this->bootProvider($this->active[$i]);
        }
    }

    /**
     * Запускает приложение.
     */
    public function run(): void
    {
        $this->bootstrap();
        $this->boot();
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Возвращает текущий экземпляр приложения.
     */
    public static function getInstance(): self
    {
        return self::$instance
            ?? throw new LogicException(
                'Приложение не создано.'
            );
    }

    /**
     * Подменяет/сбрасывает глобальный экземпляр (нужно тестам).
     */
    public static function setInstance(?self $app): void
    {
        self::$instance = $app;
    }

    /**
     * Разрешает зависимость через контейнер.
     */
    public function make(
        string $abstract,
        array $parameters = [],
    ): mixed {
        return $this->container->make(
            $abstract,
            $parameters,
        );
    }

    public function has(string $abstract): bool
    {
        return $this->container->has($abstract);
    }

    /**
     * Возвращает контейнер приложения.
     */
    public function getContainer(): ServiceContainer
    {
        return $this->container;
    }

    # -----------------------------------------------------------
    # Провайдеры
    # -----------------------------------------------------------
    #

    /**
     * Регистрирует провайдера: объектом или именем класса.
     *
     * Имя класса + DeferrableProviderContract = провайдер создастся лениво.
     * Одно и то же имя класса повторно не регистрируется.
     *
     * @param ServiceProvider|class-string<ServiceProvider> $provider
     */
    public function registerProvider(ServiceProvider|string $provider): void
    {
        if (is_string($provider)) {
            if (!is_subclass_of($provider, ServiceProvider::class)) {
                throw new LogicException(
                    "Провайдер {$provider} должен наследовать " . ServiceProvider::class . '.'
                );
            }

            if (isset($this->declared[$provider])) {
                return;
            }

            $this->declared[$provider] = true;
        }

        $this->providers[] = $provider;

        if (is_string($provider) && $this->isDeferrable($provider)) {
            $this->defer($provider);

            return;
        }

        $this->activate($provider);
    }

    /**
     * Что передали в registerProvider() (отложенные в том числе).
     *
     * @return list<ServiceProvider|class-string<ServiceProvider>>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Создавшиеся к этому моменту провайдеры.
     *
     * @return list<ServiceProvider>
     */
    public function getActiveProviders(): array
    {
        return $this->active;
    }

    /**
     * @param class-string<ServiceProvider> $class
     */
    private function isDeferrable(string $class): bool
    {
        return is_subclass_of($class, DeferrableProviderContract::class)
            && $class::provides() !== [];
    }

    /**
     * Вешает на контейнер загрузчик: провайдер создастся при первом
     * запросе любого из его сервисов.
     *
     * @param class-string<ServiceProvider&DeferrableProviderContract> $class
     */
    private function defer(string $class): void
    {
        $abstracts = $class::provides();

        $loader = function () use ($class, $abstracts): void {
            # Сначала снимаем ленивость, иначе register() зациклится на самом себе.
            #
            $this->container->undefer($abstracts);
            $this->activate($class);
        };

        foreach ($abstracts as $abstract) {
            $this->container->defer($abstract, $loader);
        }
    }

    /**
     * Создаёт провайдера (если дали класс), вызывает register(),
     * а если приложение уже запущено, то и boot().
     *
     * @param ServiceProvider|class-string<ServiceProvider> $provider
     */
    private function activate(ServiceProvider|string $provider): void
    {
        $instance = is_string($provider)
            ? $this->container->make($provider)
            : $provider;

        $this->active[] = $instance;

        $instance->register();

        if ($this->booted) {
            $this->bootProvider($instance);
        }
    }

    private function bootProvider(ServiceProvider $provider): void
    {
        $id = spl_object_id($provider);

        if (isset($this->bootedIds[$id])) {
            return;
        }

        $this->bootedIds[$id] = true;

        $provider->boot();
    }

    # -----------------------------------------------------------
    # Удобный биндинг для провайдеров.
    # -----------------------------------------------------------
    #
    public function bind(
        string               $abstract,
        \Closure|string|null $concrete = null,
        bool                 $shared = false,
    ): static
    {
        $this->container->bind($abstract, $concrete, $shared);

        return $this;
    }

    public function singleton(
        string               $abstract,
        \Closure|string|null $concrete = null,
    ): static
    {
        $this->container->singleton($abstract, $concrete);

        return $this;
    }

    public function instance(
        string $abstract,
        mixed $instance,
    ): mixed { return $this->container->instance($abstract, $instance); }

    public function envPath(): ?string { return $this->envFile; }
}