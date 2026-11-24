<?php

declare(strict_types=1);

use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\Exceptions\SuspiciousOperationException;
use Neo\Network\Http\Request;

test('Request exposes path, method and query', function () {
    $request = Request::create('http://example.com/users/42?page=2&q=neo', 'GET');

    expect($request->method())->toBe('GET')
        ->and($request->path())->toBe('users/42')
        ->and($request->getPathInfo())->toBe('/users/42')
        ->and($request->query('page'))->toBe('2')
        ->and($request->fullUrl())->toBe('http://example.com/users/42?page=2&q=neo')
        ->and($request->url())->toBe('http://example.com/users/42')
        ->and($request->root())->toBe('http://example.com')
        ->and($request->is('users/*'))->toBeTrue()
        ->and($request->is('admin/*'))->toBeFalse();
});

test('Request root path is a single slash', function () {
    expect(Request::create('/')->path())->toBe('/')
        ->and(Request::create('/')->is('/'))->toBeTrue();
});

test('Request normalizes duplicate slashes and decodes the path', function () {
    $request = Request::create('http://x.test//admin///users%20list');

    expect($request->getPathInfo())->toBe('/admin/users list');
});

test('Request rejects NUL bytes in the path', function () {
    $request = Request::create('http://x.test/a%00b');

    expect(fn () => $request->getPathInfo())->toThrow(SuspiciousOperationException::class);
});

test('Request method spoofing works only for POST and only to PUT, PATCH, DELETE', function () {
    $post = Request::create('/x', 'POST', ['_method' => 'DELETE']);
    $toGet = Request::create('/x', 'POST', ['_method' => 'GET']);
    $fromGet = Request::create('/x', 'GET', ['_method' => 'DELETE']);

    expect($post->method())->toBe('DELETE')
        ->and($post->getRealMethod())->toBe('POST')
        ->and($toGet->method())->toBe('POST')
        ->and($fromGet->method())->toBe('GET');
});

test('Request input merges body over query and supports dot notation', function () {
    $request = Request::create('/x?a=1&b=2', 'POST', ['b' => 'body', 'user' => ['name' => 'Neo']]);

    expect($request->input('a'))->toBe('1')
        ->and($request->input('b'))->toBe('body')
        ->and($request->input('user.name'))->toBe('Neo')
        ->and($request->has('user.name'))->toBeTrue()
        ->and($request->only('a', 'b'))->toBe(['a' => '1', 'b' => 'body'])
        ->and($request->except('a'))->toHaveKey('b')
        ->and($request->filled('a'))->toBeTrue()
        ->and($request->integer('a'))->toBe(1);
});

test('Request reads JSON bodies and rejects malformed JSON with 400', function () {
    $ok = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":{"b":5}}');
    $bad = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{broken');

    expect($ok->isJson())->toBeTrue()
        ->and($ok->input('a.b'))->toBe(5)
        ->and(fn () => $bad->input('a'))->toThrow(HttpException::class, 'Malformed JSON.');
});

test('Request content negotiation', function () {
    $json = Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $html = Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8']);
    $xhr = Request::create('/x', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $weighted = Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html;q=0.5, application/json']);

    expect($json->expectsJson())->toBeTrue()
        ->and($html->expectsJson())->toBeFalse()
        ->and($html->acceptsHtml())->toBeTrue()
        ->and($xhr->expectsJson())->toBeTrue()
        ->and($weighted->wantsJson())->toBeTrue();
});

test('Request bearer token', function () {
    $request = Request::create('/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer abc.def']);

    expect($request->bearerToken())->toBe('abc.def')
        ->and(Request::create('/x')->bearerToken())->toBeNull();
});

test('Request rejects a malformed Host header', function () {
    $evil = Request::create('/x', 'GET', [], [], [], ['HTTP_HOST' => "evil.com\r\nX: y"]);
    $slash = Request::create('/x', 'GET', [], [], [], ['HTTP_HOST' => 'evil.com/path']);
    $ok = Request::create('/x', 'GET', [], [], [], ['HTTP_HOST' => 'Example.COM:8080']);

    expect(fn () => $evil->getHost())->toThrow(SuspiciousOperationException::class)
        ->and(fn () => $slash->getHost())->toThrow(SuspiciousOperationException::class)
        ->and($ok->getHost())->toBe('example.com');
});

test('Request ignores X-Forwarded headers from untrusted peers', function () {
    $request = Request::create('http://real.test/x', 'GET', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'evil.test',
    ]);

    expect($request->ip())->toBe('203.0.113.9')
        ->and($request->isSecure())->toBeFalse()
        ->and($request->getHost())->toBe('real.test');
});

test('Request trusts X-Forwarded headers from a trusted proxy and takes the right-most untrusted IP', function () {
    $request = Request::create('http://internal.test/x', 'GET', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.5',
        # слева подделка клиента, справа адрес, который дописал наш прокси
        'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'public.test',
    ]);
    $request->setTrustedProxies(['10.0.0.0/8']);

    expect($request->ip())->toBe('198.51.100.7')
        ->and($request->isSecure())->toBeTrue()
        ->and($request->getHost())->toBe('public.test')
        ->and($request->getScheme())->toBe('https');
});

test('Request wildcard trusted proxy and CIDR matching for IPv6', function () {
    $request = Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => 'fd00::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::5']);
    $request->setTrustedProxies(['fd00::/8']);

    expect($request->ip())->toBe('2001:db8::5');
});

test('Request merge and replace change input', function () {
    $request = Request::create('/x', 'POST', ['a' => 1]);
    $request->merge(['b' => 2]);

    expect($request->all())->toBe(['a' => 1, 'b' => 2]);

    $request->replace(['z' => 9]);

    expect($request->all())->toBe(['z' => 9]);
});

test('Request transformInput applies a callback recursively', function () {
    $request = Request::create('/x', 'POST', ['a' => ' x ', 'n' => ['b' => ' y ']]);
    $request->transformInput(fn ($v) => is_string($v) ? trim($v) : $v);

    expect($request->input('a'))->toBe('x')
        ->and($request->input('n.b'))->toBe('y');
});
