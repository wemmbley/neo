<?php

declare(strict_types=1);

use Neo\Kernel\ServiceContainer;
use Neo\Kernel\ServiceContainerException;

// --------------------------------------------------
// Fixtures
// --------------------------------------------------

interface TestMailerInterface
{
}

class TestMailer implements TestMailerInterface
{
}

class TestConfig
{
    public function __construct(
        public string $appName = 'Neo'
    )
    {
    }
}

class TestLogger
{
}

class TestUserRepository
{
    public function __construct(
        public TestLogger $logger
    )
    {
    }
}

class TestUserService
{
    public function __construct(
        public TestUserRepository $repository,
        public TestConfig         $config
    )
    {
    }
}

class TestSimpleService
{
}

class TestConstructorlessService
{
}

abstract class TestAbstractService
{
}

class TestPrivateConstructorService
{
    private function __construct()
    {
    }
}

class TestServiceWithParameters
{
    public function __construct(
        public string $name,
        public int    $age,
        public float  $balance
    )
    {
    }
}

class TestServiceWithDefaults
{
    public function __construct(
        public string $name = 'Default',
        public int    $limit = 10
    )
    {
    }
}

class TestServiceWithNullable
{
    public function __construct(
        public ?TestLogger $logger = null
    )
    {
    }
}

class TestServiceWithNull
{
    public function __construct(
        public ?string $value
    )
    {
    }
}

class TestServiceWithRequired
{
    public function __construct(
        public string $value
    )
    {
    }
}

class TestCircularA
{
    public function __construct(TestCircularB $b)
    {
    }
}

class TestCircularB
{
    public function __construct(TestCircularA $a)
    {
    }
}

class TestCallable
{
    public function __invoke(string $name): string
    {
        return "Hello {$name}";
    }
}

class TestController
{
    public function show(int $id, TestLogger $logger): array
    {
        return [
            'id' => $id,
            'logger' => $logger,
        ];
    }

    public function greet(string $name = 'Guest'): string
    {
        return "Hello {$name}";
    }
}

class TestInvokableController
{
    public function __invoke(string $name): string
    {
        return "Hello {$name}";
    }
}

// --------------------------------------------------
// Registration
// --------------------------------------------------

describe('Registration', function () {

    it('registers a binding', function () {
        $container = new ServiceContainer();

        $container->bind(TestMailerInterface::class, TestMailer::class);

        expect($container->has(TestMailerInterface::class))->toBeTrue();
    });

    it('registers a singleton', function () {
        $container = new ServiceContainer();

        $container->singleton(TestMailerInterface::class, TestMailer::class);

        expect($container->has(TestMailerInterface::class))->toBeTrue();
    });

    it('supports method chaining', function () {
        $container = new ServiceContainer();

        expect(
            $container
                ->bind(TestMailerInterface::class, TestMailer::class)
                ->singleton(TestLogger::class)
        )->toBe($container);
    });

    it('registers an instance', function () {
        $container = new ServiceContainer();
        $instance = new TestLogger();

        expect($container->instance(TestLogger::class, $instance))
            ->toBe($instance);

        expect($container->has(TestLogger::class))->toBeTrue();
    });

    it('forgets a binding', function () {
        $container = new ServiceContainer();

        $container->bind(TestMailerInterface::class, TestMailer::class);
        $container->forget(TestMailerInterface::class);

        expect($container->has(TestMailerInterface::class))->toBeFalse();
    });

    it('forgets a registered instance', function () {
        $container = new ServiceContainer();

        $container->instance(TestLogger::class, new TestLogger());
        $container->forget(TestLogger::class);

        expect($container->has(TestLogger::class))->toBeFalse();
    });

});

// --------------------------------------------------
// Object lifecycle
// --------------------------------------------------

describe('Object lifecycle', function () {

    it('creates a new instance for bind', function () {
        $container = new ServiceContainer();

        $container->bind(TestSimpleService::class);

        $first = $container->make(TestSimpleService::class);
        $second = $container->make(TestSimpleService::class);

        expect($first)->toBeInstanceOf(TestSimpleService::class);
        expect($second)->toBeInstanceOf(TestSimpleService::class);
        expect($first)->not->toBe($second);
    });

    it('reuses singleton instances', function () {
        $container = new ServiceContainer();

        $container->singleton(TestSimpleService::class);

        $first = $container->make(TestSimpleService::class);
        $second = $container->make(TestSimpleService::class);

        expect($first)->toBe($second);
    });

    it('replaces an existing singleton instance after rebinding', function () {
        $container = new ServiceContainer();

        $container->singleton(TestSimpleService::class);

        $first = $container->make(TestSimpleService::class);

        $container->bind(TestSimpleService::class);

        $second = $container->make(TestSimpleService::class);

        expect($first)->not->toBe($second);
    });

    it('returns an existing instance', function () {
        $container = new ServiceContainer();
        $instance = new TestLogger();

        $container->instance(TestLogger::class, $instance);

        expect($container->make(TestLogger::class))->toBe($instance);
    });

    it('resolves aliases', function () {
        $container = new ServiceContainer();

        $container->bind(TestMailerInterface::class, TestMailer::class);

        expect($container->make(TestMailerInterface::class))
            ->toBeInstanceOf(TestMailer::class);
    });

    it('resolves closures', function () {
        $container = new ServiceContainer();

        $container->bind(
            TestMailerInterface::class,
            fn(ServiceContainer $container) => new TestMailer()
        );

        expect($container->make(TestMailerInterface::class))
            ->toBeInstanceOf(TestMailer::class);
    });

    it('passes parameters into closures', function () {
        $container = new ServiceContainer();

        $container->bind(
            TestConfig::class,
            fn(ServiceContainer $container, array $params) => new TestConfig($params['appName'])
        );

        $config = $container->make(TestConfig::class, [
            'appName' => 'My Application',
        ]);

        expect($config->appName)->toBe('My Application');
    });

    it('supports get as an alias for make', function () {
        $container = new ServiceContainer();

        expect($container->get(TestSimpleService::class))
            ->toBeInstanceOf(TestSimpleService::class);
    });

});

// --------------------------------------------------
// Automatic dependency resolution
// --------------------------------------------------

describe('Dependency resolution', function () {

    it('automatically resolves constructor dependencies', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestUserService::class);

        expect($service)->toBeInstanceOf(TestUserService::class);
        expect($service->repository)->toBeInstanceOf(TestUserRepository::class);
        expect($service->repository->logger)->toBeInstanceOf(TestLogger::class);
        expect($service->config)->toBeInstanceOf(TestConfig::class);
    });

    it('uses explicitly registered dependencies', function () {
        $container = new ServiceContainer();
        $logger = new TestLogger();

        $container->instance(TestLogger::class, $logger);

        $repository = $container->make(TestUserRepository::class);

        expect($repository->logger)->toBe($logger);
    });

    it('supports constructor parameters by name', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestServiceWithParameters::class, [
            'name' => 'Lucifer',
            'age' => 30,
            'balance' => 150.5,
        ]);

        expect($service->name)->toBe('Lucifer');
        expect($service->age)->toBe(30);
        expect($service->balance)->toBe(150.5);
    });

    it('uses default constructor values', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestServiceWithDefaults::class);

        expect($service->name)->toBe('Default');
        expect($service->limit)->toBe(10);
    });

    it('allows overriding default values', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestServiceWithDefaults::class, [
            'name' => 'Custom',
            'limit' => 50,
        ]);

        expect($service->name)->toBe('Custom');
        expect($service->limit)->toBe(50);
    });

    it('supports nullable dependencies', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestServiceWithNull::class);

        expect($service->value)->toBeNull();
    });

    it('uses null default for nullable class dependencies', function () {
        $container = new ServiceContainer();

        $container->bind(TestLogger::class, TestLogger::class);

        $service = $container->make(TestServiceWithNullable::class);

        expect($service->logger)->toBeInstanceOf(TestLogger::class);
    });

    it('does not instantiate classes without constructors', function () {
        $container = new ServiceContainer();

        expect($container->make(TestConstructorlessService::class))
            ->toBeInstanceOf(TestConstructorlessService::class);
    });

});

// --------------------------------------------------
// Exceptions
// --------------------------------------------------

describe('Exceptions', function () {

    it('throws when class does not exist', function () {
        $container = new ServiceContainer();

        expect(fn() => $container->make('UnknownNeoService'))
            ->toThrow(ServiceContainerException::class, 'не найден');
    });

    it('throws when interface has no binding', function () {
        $container = new ServiceContainer();

        expect(fn() => $container->make(TestMailerInterface::class))
            ->toThrow(ServiceContainerException::class, 'нет привязки');
    });

    it('throws when class is abstract', function () {
        $container = new ServiceContainer();

        expect(fn() => $container->make(TestAbstractService::class))
            ->toThrow(ServiceContainerException::class, 'нельзя создать');
    });

    it('throws when constructor is private', function () {
        $container = new ServiceContainer();

        expect(fn() => $container->make(TestPrivateConstructorService::class))
            ->toThrow(ServiceContainerException::class);
    });

    it('throws when required argument cannot be resolved', function () {
        $container = new ServiceContainer();

        expect(fn() => $container->make(TestServiceWithRequired::class))
            ->toThrow(ServiceContainerException::class, 'value');
    });

    it('detects circular dependencies', function () {
        $container = new ServiceContainer();

        expect(fn() => $container->make(TestCircularA::class))
            ->toThrow(ServiceContainerException::class, 'Циклическая зависимость');
    });

});

// --------------------------------------------------
// Argument coercion
// --------------------------------------------------

describe('Argument coercion', function () {

    it('converts numeric strings to integers', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestServiceWithParameters::class, [
            'name' => 'Neo',
            'age' => '25',
            'balance' => '100',
        ]);

        expect($service->age)->toBe(25);
        expect($service->age)->toBeInt();
    });

    it('converts numeric strings to floats', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestServiceWithParameters::class, [
            'name' => 'Neo',
            'age' => 25,
            'balance' => '125.75',
        ]);

        expect($service->balance)->toBe(125.75);
        expect(is_float($service->balance))->toBeTrue();
    });

    it('supports negative integers', function () {
        $container = new ServiceContainer();

        $service = $container->make(TestServiceWithParameters::class, [
            'name' => 'Neo',
            'age' => '-10',
            'balance' => '0',
        ]);

        expect($service->age)->toBe(-10);
    });

    it('does not convert non-numeric strings', function () {
        $container = new ServiceContainer();

        expect(fn () => $container->make(TestServiceWithParameters::class, [
            'name' => 'Neo',
            'age' => 'invalid',
            'balance' => '10',
        ]))->toThrow(TypeError::class);
    });

});

// --------------------------------------------------
// Method invocation
// --------------------------------------------------

describe('Method invocation', function () {

    it('calls a closure', function () {
        $container = new ServiceContainer();

        $result = $container->call(
            fn(string $name) => "Hello {$name}",
            ['name' => 'Neo']
        );

        expect($result)->toBe('Hello Neo');
    });

    it('calls a named function', function () {
        $container = new ServiceContainer();

        $function = function (string $name) {
            return "Hello {$name}";
        };

        $result = $container->call($function, [
            'name' => 'Neo',
        ]);

        expect($result)->toBe('Hello Neo');
    });

    it('calls an object method', function () {
        $container = new ServiceContainer();

        $controller = new TestController();

        $result = $container->call(
            [$controller, 'greet'],
            ['name' => 'Neo']
        );

        expect($result)->toBe('Hello Neo');
    });

    it('calls a static-style class method through the container', function () {
        $container = new ServiceContainer();

        $result = $container->call(
            [TestController::class, 'greet'],
            ['name' => 'Neo']
        );

        expect($result)->toBe('Hello Neo');
    });

    it('supports Class@method notation', function () {
        $container = new ServiceContainer();

        $result = $container->call(
            TestController::class . '@greet',
            ['name' => 'Neo']
        );

        expect($result)->toBe('Hello Neo');
    });

    it('injects method dependencies automatically', function () {
        $container = new ServiceContainer();

        $result = $container->call(
            [TestController::class, 'show'],
            ['id' => '42']
        );

        expect($result['id'])->toBe(42);
        expect($result['logger'])->toBeInstanceOf(TestLogger::class);
    });

    it('calls invokable objects', function () {
        $container = new ServiceContainer();

        $result = $container->call(new TestCallable(), [
            'name' => 'Neo',
        ]);

        expect($result)->toBe('Hello Neo');
    });

    it('calls invokable classes', function () {
        $container = new ServiceContainer();

        $result = $container->call(TestInvokableController::class, [
            'name' => 'Neo',
        ]);

        expect($result)->toBe('Hello Neo');
    });

    it('uses default method arguments', function () {
        $container = new ServiceContainer();

        $result = $container->call(
            [TestController::class, 'greet']
        );

        expect($result)->toBe('Hello Guest');
    });

});
