<?php

declare(strict_types=1);

use Neo\Network\Cookie\CookieManager;
use Neo\Network\Http\Testing\ArrayRequest;
use Neo\Network\Http\Testing\ArrayResponse;
use Neo\Network\Http\Testing\FiberRequestContext;
use Neo\Network\Session\Drivers\ArraySessionDriver;
use Neo\Network\Session\SessionManager;

function session_test_manager(?FiberRequestContext $context = null, ?ArraySessionDriver $driver = null, array $cookies = []): array
{
    $context ??= new FiberRequestContext();
    $response = new ArrayResponse();
    $context->enter(new ArrayRequest($cookies), $response);
    $driver ??= new ArraySessionDriver();
    $cookie = new CookieManager(['path' => '/', 'http_only' => true], $context);
    $manager = new SessionManager(['cookie' => 'neo_session', 'lifetime' => 120], $driver, $cookie, $context);
    return [$manager, $context, $response, $driver];
}

test('Session stores and retrieves values with dot notation', function () {
    [$session] = session_test_manager();
    $session->put('user.profile.name', 'Neo');

    expect($session->get('user.profile.name'))->toBe('Neo')
        ->and($session->get('missing', 'default'))->toBe('default')
        ->and($session->has('user.profile.name'))->toBeTrue()
        ->and($session->all()['user']['profile']['name'])->toBe('Neo');
});

test('Session supports pull, push, increment and decrement', function () {
    [$session] = session_test_manager();
    $session->put('count', 2);
    expect($session->increment('count'))->toBe(3)
        ->and($session->decrement('count', 2))->toBe(1);
    $session->push('items', 'a');
    $session->push('items', 'b');
    expect($session->get('items'))->toBe(['a', 'b'])
        ->and($session->pull('count'))->toBe(1)
        ->and($session->has('count'))->toBeFalse();
});

test('Session save persists data and queues session cookie', function () {
    [$session, , $response, $driver] = session_test_manager();
    $session->put('user_id', 42);
    $id = $session->getId();
    $session->save();

    expect($driver->read($id))->toHaveKey('user_id', 42)
        ->and($response->cookie('neo_session')->value)->toBe($id);
});

test('Session can resume from incoming cookie', function () {
    $driver = new ArraySessionDriver();
    $id = str_repeat('a', 80);
    $driver->write($id, ['user_id' => 7], time());
    [$session] = session_test_manager(driver: $driver, cookies: ['neo_session' => $id]);

    expect($session->getId())->toBe($id)
        ->and($session->get('user_id'))->toBe(7);
});

test('Session flash data is available for the next request only', function () {
    $driver = new ArraySessionDriver();
    [$first, , $response] = session_test_manager(driver: $driver);
    $first->flash('notice', 'saved');
    $id = $first->getId();
    $first->save();

    [$second] = session_test_manager(driver: $driver, cookies: ['neo_session' => $id]);
    expect($second->getFlash('notice'))->toBe('saved');
    $second->save();

    [$third] = session_test_manager(driver: $driver, cookies: ['neo_session' => $id]);
    expect($third->get('notice'))->toBeNull();
});

test('Session regenerate changes id and optionally destroys old record', function () {
    $driver = new ArraySessionDriver();
    [$session] = session_test_manager(driver: $driver);
    $session->put('x', 1);
    $old = $session->getId();
    $session->save();
    $session->regenerate(true);

    expect($session->getId())->not->toBe($old)
        ->and($driver->read($old))->toBeNull()
        ->and($session->get('x'))->toBe(1);
});

test('Session context data is isolated between fibers', function () {
    $context = new FiberRequestContext();
    $driver = new ArraySessionDriver();
    [$main] = session_test_manager($context, $driver);
    $main->put('scope', 'main');
    $fiberValue = null;
    $fiber = new Fiber(function () use ($context, $driver, &$fiberValue) {
        $response = new ArrayResponse();
        $context->enter(new ArrayRequest(), $response);
        $manager = new SessionManager(['cookie' => 'neo_session'], $driver, new CookieManager([], $context), $context);
        $fiberValue = $manager->get('scope', 'isolated');
    });
    $fiber->start();

    expect($fiberValue)->toBe('isolated')
        ->and($main->get('scope'))->toBe('main');
});

test('File session driver stores JSON through Storage disk', function () {
    $disk = new \Neo\Information\Persistence\Storage\Domain\Disk(
        new \Neo\Information\Persistence\Storage\Drivers\MemoryDriver(),
        'memory',
        true,
    );
    $driver = new \Neo\Network\Session\Drivers\FileSessionDriver($disk, 'sessions');
    $id = str_repeat('b', 80);
    $driver->write($id, ['user_id' => 9], time());

    expect($driver->read($id))->toBe(['user_id' => 9]);
    $driver->destroy($id);
    expect($driver->read($id))->toBeNull();
});

test('Session encrypts stored payload when encryption is enabled', function () {
    $driver = new ArraySessionDriver();
    $context = new FiberRequestContext();
    $response = new ArrayResponse();
    $context->enter(new ArrayRequest(), $response);
    $crypt = new \Neo\Security\Crypt\CryptManager(str_repeat('k', 64));
    $manager = new SessionManager(
        ['cookie' => 'neo_session', 'lifetime' => 120, 'encrypt' => true],
        $driver,
        new CookieManager([], $context),
        $context,
        $crypt,
    );
    $manager->put('secret', 'value');
    $id = $manager->getId();
    $manager->save();

    expect($driver->read($id))->toHaveKey('__neo_encrypted')
        ->and($driver->read($id))->not->toHaveKey('secret');
});

test('Encrypted session payload can be restored on a later request', function () {
    $driver = new ArraySessionDriver();
    $crypt = new \Neo\Security\Crypt\CryptManager(str_repeat('z', 64));
    $context = new FiberRequestContext();
    $response = new ArrayResponse();
    $context->enter(new ArrayRequest(), $response);
    $first = new SessionManager(
        ['cookie' => 'neo_session', 'lifetime' => 120, 'encrypt' => true],
        $driver,
        new CookieManager([], $context),
        $context,
        $crypt,
    );
    $first->put('secret', 'hidden');
    $id = $first->getId();
    $first->save();

    $context->leave();
    $nextResponse = new ArrayResponse();
    $context->enter(new ArrayRequest(['neo_session' => $id]), $nextResponse);
    $next = new SessionManager(
        ['cookie' => 'neo_session', 'lifetime' => 120, 'encrypt' => true],
        $driver,
        new CookieManager([], $context),
        $context,
        $crypt,
    );

    expect($next->get('secret'))->toBe('hidden');
});

test('Session replaces an unknown client-supplied session identifier', function () {
    $unknown = str_repeat('c', 80);
    [$session] = session_test_manager(cookies: ['neo_session' => $unknown]);

    expect($session->getId())->not->toBe($unknown);
});
