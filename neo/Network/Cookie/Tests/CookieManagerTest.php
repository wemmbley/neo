<?php

declare(strict_types=1);

use Neo\Network\Cookie\CookieManager;
use Neo\Network\Http\Testing\ArrayRequest;
use Neo\Network\Http\Testing\ArrayResponse;
use Neo\Network\Http\Testing\FiberRequestContext;

test('Cookie reads incoming values from request', function () {
    $context = new FiberRequestContext();
    $context->enter(new ArrayRequest(['theme' => 'dark']), new ArrayResponse());
    $cookie = new CookieManager([], $context);

    expect($cookie->get('theme'))->toBe('dark')
        ->and($cookie->get('missing', 'fallback'))->toBe('fallback')
        ->and($cookie->has('theme'))->toBeTrue()
        ->and($cookie->all())->toBe(['theme' => 'dark']);
});

test('Cookie queues outgoing values on response with configured defaults', function () {
    $context = new FiberRequestContext();
    $response = new ArrayResponse();
    $context->enter(new ArrayRequest(), $response);
    $cookie = new CookieManager(['path' => '/app', 'http_only' => true, 'same_site' => 'strict'], $context);
    $cookie->set('token', 'abc', 10);

    expect($response->cookie('token'))->not->toBeNull()
        ->and($response->cookie('token')->value)->toBe('abc')
        ->and($response->cookie('token')->path)->toBe('/app')
        ->and($response->cookie('token')->httpOnly)->toBeTrue()
        ->and($response->cookie('token')->sameSite)->toBe('strict');
});

test('Cookie forget queues an expired cookie', function () {
    $context = new FiberRequestContext();
    $response = new ArrayResponse();
    $context->enter(new ArrayRequest(), $response);
    (new CookieManager([], $context))->forget('token');

    expect($response->cookie('token')->value)->toBe('')
        ->and($response->cookie('token')->expires)->toBeLessThan(time());
});
