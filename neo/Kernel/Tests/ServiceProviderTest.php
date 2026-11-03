<?php


declare(strict_types=1);

use Neo\Kernel\Application;
use Neo\Kernel\ServiceProvider;

// --------------------------------------------------
// Fixtures
// --------------------------------------------------

class TestConcreteServiceProvider extends ServiceProvider
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
}

class TestEmptyServiceProvider extends ServiceProvider
{
}

// --------------------------------------------------
// Construction
// --------------------------------------------------

describe('Construction', function () {

    it('accepts an application instance', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestConcreteServiceProvider($app);

        expect($provider)->toBeInstanceOf(ServiceProvider::class);
    });

    it('stores the application instance', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestConcreteServiceProvider($app);

        $property = new ReflectionProperty(ServiceProvider::class, 'app');

        expect($property->getValue($provider))->toBe($app);
    });

});

// --------------------------------------------------
// Default behavior
// --------------------------------------------------

describe('Default behavior', function () {

    it('has an empty register method', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestEmptyServiceProvider($app);

        expect($provider->register())->toBeNull();
    });

    it('has an empty boot method', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestEmptyServiceProvider($app);

        expect($provider->boot())->toBeNull();
    });

});

// --------------------------------------------------
// Register
// --------------------------------------------------

describe('Register', function () {

    it('allows register to be overridden', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestConcreteServiceProvider($app);

        $provider->register();

        expect($provider->registered)->toBeTrue();
    });

    it('does not execute register automatically during construction', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestConcreteServiceProvider($app);

        expect($provider->registered)->toBeFalse();
    });

});

// --------------------------------------------------
// Boot
// --------------------------------------------------

describe('Boot', function () {

    it('allows boot to be overridden', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestConcreteServiceProvider($app);

        $provider->boot();

        expect($provider->booted)->toBeTrue();
    });

    it('does not execute boot automatically during construction', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestConcreteServiceProvider($app);

        expect($provider->booted)->toBeFalse();
    });

});

// --------------------------------------------------
// Lifecycle
// --------------------------------------------------

describe('Lifecycle', function () {

    it('supports register followed by boot', function () {
        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $provider = new TestConcreteServiceProvider($app);

        $provider->register();
        $provider->boot();

        expect($provider->registered)->toBeTrue();
        expect($provider->booted)->toBeTrue();
    });

});
