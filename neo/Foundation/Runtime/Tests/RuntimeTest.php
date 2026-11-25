<?php

declare(strict_types=1);

use Neo\Foundation\Runtime\AsyncIoMode;
use Neo\Foundation\Runtime\Capabilities;
use Neo\Foundation\Runtime\Context;
use Neo\Foundation\Runtime\Exceptions\PoolExhaustedException;
use Neo\Foundation\Runtime\Lifecycle;
use Neo\Foundation\Runtime\Pool\Pool;
use Neo\Foundation\Runtime\Pool\PoolOptions;
use Neo\Foundation\Runtime\Resettable;
use Neo\Foundation\Runtime\Runtime;
use Neo\Foundation\Runtime\Sync\SyncRuntime;

beforeEach(function () {
    Runtime::use(null);
});

test('sync runtime is the default and has no concurrency', function () {
    $caps = Runtime::capabilities();

    expect(Runtime::current()->name())->toBe('sync')
        ->and($caps->concurrentRequestsPerWorker())->toBeFalse()
        ->and($caps->requiresConnectionPool())->toBeFalse()
        ->and($caps->asyncIo())->toBe(AsyncIoMode::None)
        ->and(Capabilities::swoole()->requiresConnectionPool())->toBeTrue()
        ->and(Capabilities::swoole()->asyncIo())->toBe(AsyncIoMode::Coroutines)
        ->and(Capabilities::frankenPhp()->threads())->toBeTrue();
});

test('Context stores, overrides and forgets values', function () {
    Context::set('a', 1);

    expect(Context::get('a'))->toBe(1)
        ->and(Context::has('a'))->toBeTrue()
        ->and(Context::getOrSet('b', fn () => 'lazy'))->toBe('lazy')
        ->and(Context::getOrSet('b', fn () => 'other'))->toBe('lazy')
        ->and(Context::override('a', fn ($v) => $v + 1))->toBe(2);

    Context::destroy('a');
    Context::destroy('b');

    expect(Context::has('a'))->toBeFalse()->and(Context::get('a', 'def'))->toBe('def');
});

test('Context is isolated per Fiber in sync runtime', function () {
    Context::set('k', 'main');

    $inside = null;
    $fiber = new Fiber(function () use (&$inside) {
        $inside = Context::get('k', 'none');
        Context::set('k', 'fiber');
    });
    $fiber->start();

    expect($inside)->toBe('none')->and(Context::get('k'))->toBe('main');
    Context::destroy('k');
});

test('sync AsyncIO runs sequentially and keeps errors until await', function () {
    $async = Runtime::current()->async();

    $f = $async->run(fn () => throw new LogicException('boom'));

    expect($f->isDone())->toBeTrue()
        ->and($async->all(['x' => fn () => 1, 'y' => fn () => 2]))->toBe(['x' => 1, 'y' => 2])
        ->and($async->timeout(1.0, fn () => 'ok'))->toBe('ok')
        ->and(fn () => $f->await())->toThrow(LogicException::class);
});

test('Lifecycle resets resettables, isolates failures, skips uninstantiated services', function () {
    $log = ini_get('error_log');
    ini_set('error_log', sys_get_temp_dir() . '/neo-runtime-test.log');

    $calls = [];
    $service = new class($calls) implements Resettable {
        public function __construct(private array &$calls) {}
        public function reset(): void { $this->calls[] = 'service'; }
    };

    $life = new Lifecycle();
    $life->resettable(function () { throw new RuntimeException('bad'); })
        ->resettable($service)
        ->resettable(function () use (&$calls) { $calls[] = 'closure'; })
        ->resettable('Not\\Existing\\Service');
    $life->onWorkerStart(function () use (&$calls) { $calls[] = 'start'; });

    $life->workerStart();
    $life->requestEnd();

    ini_set('error_log', (string) $log);

    expect($calls)->toBe(['start', 'service', 'closure']);
});

test('Pool reuses, limits, resets and replaces broken resources', function () {
    $made = 0;
    $reset = 0;
    $pool = new Pool(
        factory: function () use (&$made) { $made++; return new stdClass(); },
        options: new PoolOptions(min: 0, max: 2, acquireTimeout: 0.01),
        runtime: new SyncRuntime(),
        reset: function () use (&$reset) { $reset++; },
    );

    $a = $pool->acquire();
    $b = $pool->acquire();

    expect($pool->stats())->toBe(['total' => 2, 'idle' => 0, 'leased' => 2, 'max' => 2])
        ->and(fn () => $pool->acquire())->toThrow(PoolExhaustedException::class);

    $pool->release($a);
    $again = $pool->acquire();
    expect($again)->toBe($a)->and($made)->toBe(2)->and($reset)->toBe(1);

    $pool->release($again, broken: true);
    $fresh = $pool->acquire();
    expect($fresh)->not->toBe($a)->and($made)->toBe(3);

    $pool->release($fresh);
    $pool->release($fresh); # повторный возврат безопасен
    $pool->release($b);
    expect($pool->stats()['leased'])->toBe(0)->and($pool->stats()['total'])->toBe(2);

    $pool->close();
    expect(fn () => $pool->acquire())->toThrow(RuntimeException::class);
});

test('Pool validates after idle and recreates dead resources', function () {
    $made = 0;
    $pool = new Pool(
        factory: function () use (&$made) { $made++; return new stdClass(); },
        options: new PoolOptions(max: 1, validateAfter: 0.0),
        runtime: new SyncRuntime(),
        validate: fn () => false,
    );

    $pool->release($pool->acquire());
    $pool->acquire();

    expect($made)->toBe(2);
});

test('Pool does not lose the slot when the factory fails', function () {
    $fail = true;
    $pool = new Pool(
        factory: function () use (&$fail) { if ($fail) { throw new RuntimeException('db down'); } return new stdClass(); },
        options: new PoolOptions(max: 1, acquireTimeout: 0.01),
        runtime: new SyncRuntime(),
    );

    expect(fn () => $pool->acquire())->toThrow(RuntimeException::class);
    $fail = false;
    expect($pool->acquire())->toBeInstanceOf(stdClass::class);
});

test('Pool prune closes idle resources above min', function () {
    $pool = new Pool(
        factory: fn () => new stdClass(),
        options: new PoolOptions(min: 1, max: 3, idleTimeout: 0.0),
        runtime: new SyncRuntime(),
    );

    $a = $pool->acquire();
    $b = $pool->acquire();
    $pool->release($a);
    $pool->release($b);
    usleep(2000);

    expect($pool->prune())->toBe(1)->and($pool->stats()['total'])->toBe(1);
});

test('Pool close destroys idle resources without touching the channel', function () {
    $destroyed = 0;
    $pool = new Pool(
        factory: fn () => new stdClass(),
        options: new PoolOptions(max: 3),
        runtime: new SyncRuntime(),
        destroy: function () use (&$destroyed) { $destroyed++; },
    );

    $a = $pool->acquire();
    $b = $pool->acquire();
    $pool->release($a);

    $pool->close();
    $pool->close(); # повторное закрытие безопасно

    expect($destroyed)->toBe(1);

    $pool->release($b); # выданный до close() ресурс закрывается при возврате
    expect($destroyed)->toBe(2)->and($pool->stats()['total'])->toBe(0);
});
