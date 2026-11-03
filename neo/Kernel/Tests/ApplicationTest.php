<?php


declare(strict_types=1);

use Neo\Kernel\Application;
use Neo\Kernel\ServiceContainer;
use Neo\Kernel\ServiceProvider;

// --------------------------------------------------
// Fixtures
// --------------------------------------------------

class TestApplicationLogger
{
    public function __construct(
        public string $channel = 'default'
    )
    {
    }
}

class TestApplicationProvider extends ServiceProvider
{
    public bool $registered = false;
    public bool $booted = false;

    public function register(): void
    {
        $this->registered = true;
    }

    public function boot(): void
    {
        $this->booted = true;
    }

    public function getApplication(): Application
    {
        return $this->app;
    }
}

class TestLifecycleProviderA extends ServiceProvider
{
    public function __construct(
        Application   $app,
        private array &$events
    )
    {
        parent::__construct($app);
    }

    public function register(): void
    {
        $this->events[] = 'register_a';
    }

    public function boot(): void
    {
        $this->events[] = 'boot_a';
    }
}

class TestLifecycleProviderB extends ServiceProvider
{
    public function __construct(
        Application   $app,
        private array &$events
    )
    {
        parent::__construct($app);
    }

    public function register(): void
    {
        $this->events[] = 'register_b';
    }

    public function boot(): void
    {
        $this->events[] = 'boot_b';
    }
}

// --------------------------------------------------
// Helpers
// --------------------------------------------------

function resetApplicationInstance(): void
{
    $property = new ReflectionProperty(Application::class, 'instance');
    $property->setValue(null, null);
}

beforeEach(function () {
    resetApplicationInstance();
});

afterEach(function () {
    resetApplicationInstance();
});

// --------------------------------------------------
// Construction
// --------------------------------------------------

describe('Construction', function () {

    it('creates an application instance', function () {
        $app = new Application(new ServiceContainer());

        expect($app)->toBeInstanceOf(Application::class);
    });

    it('stores the container', function () {
        $container = new ServiceContainer();

        $app = new Application($container);

        expect($app->getContainer())->toBe($container);
    });

    it('accepts providers in constructor', function () {
        $provider = new stdClass();

        $app = new Application(
            new ServiceContainer(),
            [$provider]
        );

        expect($app->getProviders())->toBe([$provider]);
    });

});

// --------------------------------------------------
// Singleton
// --------------------------------------------------

describe('Singleton', function () {

    it('stores itself as global instance', function () {
        $app = new Application(new ServiceContainer());

        expect(Application::getInstance())->toBe($app);
    });

    it('returns the latest application instance', function () {
        $first = new Application(new ServiceContainer());
        $second = new Application(new ServiceContainer());

        expect(Application::getInstance())->toBe($second);
        expect(Application::getInstance())->not->toBe($first);
    });

    it('throws when application does not exist', function () {
        resetApplicationInstance();

        expect(fn() => Application::getInstance())
            ->toThrow(LogicException::class, 'Приложение не создано.');
    });

});

// --------------------------------------------------
// Container
// --------------------------------------------------

describe('Container', function () {

    it('returns the service container', function () {
        $container = new ServiceContainer();

        $app = new Application($container);

        expect($app->getContainer())->toBe($container);
    });

    it('resolves services through make', function () {
        $container = new ServiceContainer();

        $container->instance(
            TestApplicationLogger::class,
            new TestApplicationLogger('neo')
        );

        $app = new Application($container);

        $logger = $app->make(TestApplicationLogger::class, []);

        expect($logger)->toBeInstanceOf(TestApplicationLogger::class);
        expect($logger->channel)->toBe('neo');
    });

    it('forwards constructor parameters to container', function () {
        $app = new Application(new ServiceContainer());

        $logger = $app->make(TestApplicationLogger::class, [
            'channel' => 'application',
        ]);

        expect($logger->channel)->toBe('application');
    });

});

// --------------------------------------------------
// Providers registration
// --------------------------------------------------

describe('Provider registration', function () {

    it('registers a provider', function () {
        $app = new Application(new ServiceContainer());
        $provider = new TestApplicationProvider($app);

        $app->registerProvider($provider);

        expect($app->getProviders())->toContain($provider);
    });

    it('supports registering multiple providers', function () {
        $app = new Application(new ServiceContainer());

        $first = new TestApplicationProvider($app);
        $second = new TestApplicationProvider($app);

        $app->registerProvider($first);
        $app->registerProvider($second);

        expect($app->getProviders())->toHaveCount(2);
        expect($app->getProviders()[0])->toBe($first);
        expect($app->getProviders()[1])->toBe($second);
    });

    it('preserves provider registration order', function () {
        $app = new Application(new ServiceContainer());

        $first = new TestApplicationProvider($app);
        $second = new TestApplicationProvider($app);

        $app->registerProvider($first);
        $app->registerProvider($second);

        expect($app->getProviders())->toBe([
            $first,
            $second,
        ]);
    });

});

// --------------------------------------------------
// Boot
// --------------------------------------------------

describe('Boot', function () {

    it('calls register on all providers', function () {
        $app = new Application(new ServiceContainer());

        $first = new TestApplicationProvider($app);
        $second = new TestApplicationProvider($app);

        $app->registerProvider($first);
        $app->registerProvider($second);

        $app->boot();

        expect($first->registered)->toBeTrue();
        expect($second->registered)->toBeTrue();
    });

    it('calls boot on all providers', function () {
        $app = new Application(new ServiceContainer());

        $first = new TestApplicationProvider($app);
        $second = new TestApplicationProvider($app);

        $app->registerProvider($first);
        $app->registerProvider($second);

        $app->boot();

        expect($first->booted)->toBeTrue();
        expect($second->booted)->toBeTrue();
    });

    it('registers all providers before booting any provider', function () {
        $app = new Application(new ServiceContainer());

        $events = [];

        $app->registerProvider(
            new TestLifecycleProviderA($app, $events)
        );

        $app->registerProvider(
            new TestLifecycleProviderB($app, $events)
        );

        $app->boot();

        expect($events)->toBe([
            'register_a',
            'register_b',
            'boot_a',
            'boot_b',
        ]);
    });

    it('does not boot providers when no providers are registered', function () {
        $app = new Application(new ServiceContainer());

        expect(fn() => $app->boot())->not->toThrow(Throwable::class);
    });

    it('passes the same application instance to providers', function () {
        $app = new Application(new ServiceContainer());

        $provider = new TestApplicationProvider($app);

        $app->registerProvider($provider);

        expect($provider->getApplication())->toBe($app);
    });

});

// --------------------------------------------------
// Run
// --------------------------------------------------

describe('Run', function () {

    it('executes the application boot lifecycle', function () {
        $app = new Application(new ServiceContainer());

        $provider = new TestApplicationProvider($app);

        $app->registerProvider($provider);

        $app->run();

        expect($provider->registered)->toBeTrue();
        expect($provider->booted)->toBeTrue();
    });

    it('uses the same lifecycle as boot', function () {
        $app = new Application(new ServiceContainer());

        $events = [];

        $app->registerProvider(
            new TestLifecycleProviderA($app, $events)
        );

        $app->run();

        expect($events)->toBe([
            'register_a',
            'boot_a',
        ]);
    });

});
