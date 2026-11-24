<?php

declare(strict_types=1);

use Neo\Information\Validator\ValidationException;
use Neo\Network\Cookie\CookieValue;
use Neo\Network\Http\Foundation\Middleware\HandleCors;
use Neo\Network\Http\Foundation\Middleware\TrimStrings;
use Neo\Network\Http\Foundation\Middleware\ConvertEmptyStringsToNull;
use Neo\Network\Http\Foundation\Middleware\ValidatePostSize;
use Neo\Network\Http\Foundation\Middleware\VerifyCsrfToken;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\Middleware\ThrottleRequests;
use Neo\Network\Http\Routing\Middleware\ValidateSignature;
use Neo\Network\Http\Testing\TestClient;
use Neo\Security\Middleware\SecurityHeaders;
use Neo\Security\Middleware\TrustHosts;
use Neo\Security\Middleware\TrustProxies;
use Neo\Security\RateLimiter\Limit;

/** Клиент с маршрутами: /form выдаёт токен, /submit принимает POST за CSRF-защитой. */
function csrf_client(?VerifyCsrfToken $csrf = null): TestClient
{
    $c = new TestClient();
    $stack = $c->webStack();

    if ($csrf !== null) {
        $stack[3] = $csrf;
    }

    $c->router->get('/form', fn () => $c->csrf->token())->middleware($stack);
    $c->router->post('/submit', fn () => 'saved')->middleware($stack);
    $c->router->delete('/item', fn () => 'deleted')->middleware($stack);

    return $c;
}

test('CSRF: POST without a token is rejected with 419', function () {
    $c = csrf_client();
    $c->get('/form');

    expect($c->post('/submit', ['a' => 1])->status())->toBe(419)
        ->and($c->post('/submit', ['_token' => 'garbage'])->status())->toBe(419);
});

test('CSRF: a valid token from the form field, header or XSRF cookie is accepted', function () {
    $c = csrf_client();
    $token = $c->get('/form')->getContent();

    expect($c->post('/submit', ['_token' => $token])->getContent())->toBe('saved')
        ->and($c->post('/submit', [], ['X-CSRF-TOKEN' => $token])->getContent())->toBe('saved')
        # JS-клиенты шлют значение куки XSRF-TOKEN (оно зашифровано) в заголовке X-XSRF-TOKEN
        ->and($c->post('/submit', [], ['X-XSRF-TOKEN' => $c->jar['XSRF-TOKEN']])->getContent())->toBe('saved');
});

test('CSRF: the token is bound to the session and cannot be used from another one', function () {
    $victim = csrf_client();
    $token = $victim->get('/form')->getContent();

    $attacker = csrf_client();
    $attacker->get('/form');

    expect($attacker->post('/submit', ['_token' => $token])->status())->toBe(419);
});

test('CSRF: spoofed _method DELETE is still protected and GET requests are not', function () {
    $c = csrf_client();
    $token = $c->get('/form')->getContent();

    expect($c->post('/item', ['_method' => 'DELETE'])->status())->toBe(419)
        ->and($c->post('/item', ['_method' => 'DELETE', '_token' => $token])->getContent())->toBe('deleted')
        ->and($c->get('/form')->status())->toBe(200);
});

test('CSRF: a request from another origin is blocked even with a valid token', function () {
    $c = csrf_client();
    $token = $c->get('/form')->getContent();

    $evil = $c->post('/submit', ['_token' => $token], ['Origin' => 'https://evil.test']);
    $same = $c->post('/submit', ['_token' => $token], ['Origin' => 'http://localhost']);

    expect($evil->status())->toBe(419)
        ->and($same->status())->toBe(200);
});

test('CSRF: excluded paths skip the check', function () {
    $c = new TestClient();
    $verify = new class($c->csrf, $c->crypt) extends VerifyCsrfToken {
        protected array $except = ['hooks/*'];
    };
    $stack = [...array_slice($c->webStack(), 0, 3), $verify];
    $c->router->post('/hooks/stripe', fn () => 'ok')->middleware($stack);
    $c->router->post('/other', fn () => 'ok')->middleware($stack);

    expect($c->post('/hooks/stripe')->status())->toBe(200)
        ->and($c->post('/other')->status())->toBe(419);
});

test('XSRF-TOKEN cookie is readable by scripts but the session cookie is not', function () {
    $c = csrf_client();
    $response = $c->get('/form');

    $xsrf = null;
    $session = null;
    foreach ($response->cookies() as $cookie) {
        $cookie->name === 'XSRF-TOKEN' && $xsrf = $cookie;
        $cookie->name === 'neo_session' && $session = $cookie;
    }

    expect($xsrf)->not->toBeNull()
        ->and($xsrf->httpOnly)->toBeFalse()
        ->and($session)->not->toBeNull()
        ->and($session->httpOnly)->toBeTrue();
});

test('Cookies are encrypted on the way out and tampered or swapped values are dropped', function () {
    $c = new TestClient();
    $stack = [$c->webStack()[0], $c->webStack()[1]];

    $c->router->get('/set', function () use ($c) {
        $c->container->make(Neo\Network\Cookie\CookieManager::class)->set('a', 'secret-a', 5);
        $c->container->make(Neo\Network\Cookie\CookieManager::class)->set('b', 'secret-b', 5);

        return 'ok';
    })->middleware($stack);
    $c->router->get('/read', fn (Request $r) => $r->cookies())->middleware($stack);

    $c->get('/set');

    expect($c->jar['a'])->not->toBe('secret-a')
        ->and($c->jar['a'])->not->toContain('secret-a')
        ->and($c->get('/read')->getData(true))->toBe(['a' => 'secret-a', 'b' => 'secret-b']);

    # Подмена значения одной куки значением другой
    $c->jar['b'] = $c->jar['a'];
    expect($c->get('/read')->getData(true))->toBe(['a' => 'secret-a']);

    # Подделка и незашифрованная кука
    $c->jar['b'] = 'plain-text';
    $c->jar['a'] = substr($c->jar['a'], 0, -3) . 'AAA';
    expect($c->get('/read')->getData(true))->toBe([]);
});

test('Session persists through the encrypted cookie and a forged session cookie gives an empty session', function () {
    $c = new TestClient();
    $stack = $c->webStack();
    $c->router->get('/put', function () use ($c) {
        $c->session->put('user', 'neo');

        return 'ok';
    })->middleware($stack);
    $c->router->get('/get', fn () => (string) $c->session->get('user', 'none'))->middleware($stack);

    $c->get('/put');

    expect($c->get('/get')->getContent())->toBe('neo');

    $c->jar['neo_session'] = 'forged-value';

    expect($c->get('/get')->getContent())->toBe('none');
});

test('Session is saved even when the route throws, so flashed errors survive', function () {
    $c = new TestClient();
    $stack = $c->webStack();
    $c->router->get('/token', fn () => $c->csrf->token())->middleware($stack);
    $c->router->post('/save', function (Request $request) {
        $request->validate(['title' => 'required']);
    })->middleware($stack);
    $c->router->get('/page', fn () => json_encode(['errors' => $c->session->get('errors'), 'old' => $c->session->get('_old_input')]))
        ->middleware($stack);

    $token = $c->get('/token')->getContent();

    $response = $c->post('/save', ['_token' => $token, 'title' => '', 'keep' => 'me'], ['Referer' => 'http://localhost/page']);

    expect($response->status())->toBe(302)
        ->and($response->headers()->get('Location'))->toBe('http://localhost/page');

    $page = json_decode($c->get('/page')->getContent(), true);

    expect($page['errors'])->toHaveKey('title')
        ->and($page['old'])->toBe(['title' => '', 'keep' => 'me']);

    # flash живёт один запрос
    $again = json_decode($c->get('/page')->getContent(), true);

    expect($again['errors'])->toBeNull();
});

test('back() ignores a Referer that points to another site (no open redirect)', function () {
    $c = new TestClient();
    $redirector = new Neo\Network\Http\Redirector($c->url, $c->context);
    $c->router->get('/go', fn () => $redirector->back());

    $evil = $c->get('/go', [], ['Referer' => 'https://evil.test/phish']);
    $good = $c->get('/go', [], ['Referer' => 'http://localhost/page']);
    $none = $c->get('/go');

    expect($evil->headers()->get('Location'))->toBe('http://localhost/')
        ->and($good->headers()->get('Location'))->toBe('http://localhost/page')
        ->and($none->headers()->get('Location'))->toBe('http://localhost/');
});

test('Validation failure answers 422 JSON for API clients', function () {
    $c = new TestClient();
    $c->router->post('/api/items', fn (Request $r) => $r->validate(['name' => 'required']));

    $response = $c->json('POST', '/api/items', []);

    expect($response->status())->toBe(422)
        ->and($response->getData(true))->toHaveKey('errors');
});

test('CORS: preflight from an allowed origin gets the right headers without needing a route', function () {
    config(['cors' => [
        'paths' => ['api/*'],
        'allowed_origins' => ['https://app.test'],
        'allowed_methods' => ['GET', 'POST'],
        'allowed_headers' => ['*'],
        'max_age' => 600,
        'supports_credentials' => false,
    ]]);

    $c = new TestClient();
    $c->registry->global([HandleCors::class]);
    $c->container->instance(HandleCors::class, new HandleCors());

    $ok = $c->call('OPTIONS', '/api/anything', [], [
        'Origin' => 'https://app.test',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'content-type, x-token',
    ]);
    $deniedOrigin = $c->call('OPTIONS', '/api/anything', [], ['Origin' => 'https://evil.test', 'Access-Control-Request-Method' => 'POST']);
    $deniedMethod = $c->call('OPTIONS', '/api/anything', [], ['Origin' => 'https://app.test', 'Access-Control-Request-Method' => 'DELETE']);
    $otherPath = $c->call('OPTIONS', '/web/page', [], ['Origin' => 'https://app.test', 'Access-Control-Request-Method' => 'POST']);

    expect($ok->status())->toBe(204)
        ->and($ok->headers()->get('Access-Control-Allow-Origin'))->toBe('https://app.test')
        ->and($ok->headers()->get('Access-Control-Allow-Methods'))->toBe('GET, POST')
        ->and($ok->headers()->get('Access-Control-Allow-Headers'))->toBe('content-type, x-token')
        ->and($ok->headers()->get('Access-Control-Max-Age'))->toBe('600')
        ->and($ok->headers()->contains('Vary', 'Origin'))->toBeTrue()
        ->and($deniedOrigin->headers()->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($deniedMethod->headers()->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($otherPath->headers()->has('Access-Control-Allow-Origin'))->toBeFalse();
});

test('CORS: wildcard is never combined with credentials, and actual responses are tagged', function () {
    config(['cors' => [
        'paths' => ['api/*'],
        'allowed_origins' => ['*'],
        'supports_credentials' => true,
    ]]);

    $c = new TestClient();
    $c->registry->global([HandleCors::class]);
    $c->container->instance(HandleCors::class, new HandleCors());
    $c->router->get('/api/data', fn () => 'x');

    $response = $c->get('/api/data', [], ['Origin' => 'https://anything.test']);

    expect($response->headers()->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers()->has('Access-Control-Allow-Credentials'))->toBeFalse();

    config(['cors' => ['paths' => ['api/*'], 'allowed_origins' => ['https://ok.test'], 'supports_credentials' => true, 'exposed_headers' => ['X-Total']]]);

    $ok = $c->get('/api/data', [], ['Origin' => 'https://ok.test']);

    expect($ok->headers()->get('Access-Control-Allow-Origin'))->toBe('https://ok.test')
        ->and($ok->headers()->get('Access-Control-Allow-Credentials'))->toBe('true')
        ->and($ok->headers()->get('Access-Control-Expose-Headers'))->toBe('X-Total');
});

test('Throttle: numeric limits return 429 with Retry-After and X-RateLimit headers', function () {
    $c = new TestClient();
    $c->container->instance(ThrottleRequests::class, new ThrottleRequests($c->limiter));
    $c->router->get('/limited', fn () => 'ok')->middleware('throttle:2,1');
    $c->registry->register('throttle', ThrottleRequests::class);

    $first = $c->get('/limited');
    $c->get('/limited');
    $third = $c->get('/limited');

    expect($first->status())->toBe(200)
        ->and($first->headers()->get('X-RateLimit-Limit'))->toBe('2')
        ->and($first->headers()->get('X-RateLimit-Remaining'))->toBe('1')
        ->and($third->status())->toBe(429)
        ->and((int) $third->headers()->get('Retry-After'))->toBeGreaterThan(0)
        ->and($third->headers()->get('X-RateLimit-Remaining'))->toBe('0');
});

test('Throttle: named limiters count per key and different clients do not affect each other', function () {
    $c = new TestClient();
    $c->limiter->for('login', fn (Request $r) => Limit::perMinute(1)->by($r->input('email')));
    $c->container->instance(ThrottleRequests::class, new ThrottleRequests($c->limiter));
    $c->registry->register('throttle', ThrottleRequests::class);
    $c->router->post('/login', fn () => 'try')->middleware('throttle:login');

    expect($c->post('/login', ['email' => 'a@x'])->status())->toBe(200)
        ->and($c->post('/login', ['email' => 'a@x'])->status())->toBe(429)
        ->and($c->post('/login', ['email' => 'b@x'])->status())->toBe(200);
});

test('Throttle: an undefined named limiter fails loudly instead of disabling the limit', function () {
    $c = new TestClient();
    $c->container->instance(ThrottleRequests::class, new ThrottleRequests($c->limiter));
    $c->registry->register('throttle', ThrottleRequests::class);
    $c->router->get('/x', fn () => 'ok')->middleware('throttle:nonexistent');

    expect($c->get('/x')->status())->toBe(500);
});

test('Throttle: forged X-Forwarded-For does not dodge the limit unless the proxy is trusted', function () {
    $c = new TestClient();
    $c->container->instance(ThrottleRequests::class, new ThrottleRequests($c->limiter));
    $c->registry->register('throttle', ThrottleRequests::class);
    $c->router->get('/x', fn () => 'ok')->middleware('throttle:1,1');

    $first = $c->get('/x', [], ['X-Forwarded-For' => '1.1.1.1']);
    $second = $c->get('/x', [], ['X-Forwarded-For' => '2.2.2.2']);

    expect($first->status())->toBe(200)
        ->and($second->status())->toBe(429);
});

test('SecurityHeaders adds protective headers, HSTS only over HTTPS, and never overrides the app', function () {
    config(['security.headers' => [
        'csp' => "script-src 'nonce-{nonce}'",
        'hsts' => ['max_age' => 100, 'include_subdomains' => false],
    ]]);

    $c = new TestClient();
    $c->container->instance(SecurityHeaders::class, new SecurityHeaders(new Neo\Security\Headers\CspNonce($c->context)));
    $c->registry->global([SecurityHeaders::class]);
    $c->router->get('/p', fn () => 'ok');
    $c->router->get('/own', fn () => new Response('x', 200, ['X-Frame-Options' => 'DENY']));

    $http = $c->get('/p');
    $https = $c->get('https://localhost/p');
    $own = $c->get('/own');

    expect($http->headers()->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($http->headers()->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($http->headers()->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($http->headers()->has('Strict-Transport-Security'))->toBeFalse()
        ->and($https->headers()->get('Strict-Transport-Security'))->toBe('max-age=100')
        ->and($http->headers()->get('Content-Security-Policy'))->toMatch("/^script-src 'nonce-[A-Za-z0-9_-]{22}'$/")
        ->and($own->headers()->get('X-Frame-Options'))->toBe('DENY');

    config(['security.headers' => []]);
});

test('CSP nonce differs for every request', function () {
    config(['security.headers' => ['csp' => "script-src 'nonce-{nonce}'"]]);

    $c = new TestClient();
    $c->container->instance(SecurityHeaders::class, new SecurityHeaders(new Neo\Security\Headers\CspNonce($c->context)));
    $c->registry->global([SecurityHeaders::class]);
    $c->router->get('/p', fn () => 'ok');

    $a = $c->get('/p')->headers()->get('Content-Security-Policy');
    $b = $c->get('/p')->headers()->get('Content-Security-Policy');

    expect($a)->not->toBe($b);

    config(['security.headers' => []]);
});

test('TrustHosts rejects unexpected Host values and accepts configured ones', function () {
    config(['security.trusted_hosts' => ['example.com', '*.example.com', '#^api\d+\.test$#']]);

    $c = new TestClient();
    $c->container->instance(TrustHosts::class, new TrustHosts());
    $c->registry->global([TrustHosts::class]);
    $c->router->get('/p', fn () => 'ok');

    $statuses = [];
    foreach (['example.com', 'shop.example.com', 'api7.test', 'evil.com', 'example.com.evil.com'] as $host) {
        $statuses[$host] = $c->get('/p', [], ['Host' => $host])->status();
    }

    config(['security.trusted_hosts' => []]);

    expect($statuses)->toBe([
        'example.com' => 200,
        'shop.example.com' => 200,
        'api7.test' => 200,
        'evil.com' => 400,
        'example.com.evil.com' => 400,
    ]);
});

test('TrustProxies makes the client IP come from X-Forwarded-For only for configured proxies', function () {
    config(['security.proxies' => ['10.0.0.0/8']]);

    $c = new TestClient();
    $c->container->instance(TrustProxies::class, new TrustProxies());
    $c->registry->global([TrustProxies::class]);
    $c->router->get('/ip', fn (Request $r) => (string) $r->ip());

    $fromProxy = $c->call('GET', '/ip', [], ['X-Forwarded-For' => '198.51.100.7'], null, ['REMOTE_ADDR' => '10.1.1.1']);
    $direct = $c->call('GET', '/ip', [], ['X-Forwarded-For' => '198.51.100.7'], null, ['REMOTE_ADDR' => '203.0.113.5']);

    config(['security.proxies' => []]);

    expect($fromProxy->getContent())->toBe('198.51.100.7')
        ->and($direct->getContent())->toBe('203.0.113.5');
});

test('TrimStrings and ConvertEmptyStringsToNull clean input but keep passwords intact', function () {
    $c = new TestClient();
    $c->container->instance(TrimStrings::class, new TrimStrings());
    $c->container->instance(ConvertEmptyStringsToNull::class, new ConvertEmptyStringsToNull());
    $c->registry->global([TrimStrings::class, ConvertEmptyStringsToNull::class]);
    $c->router->post('/in', fn (Request $r) => $r->all());

    $data = $c->post('/in', ['name' => '  Neo  ', 'bio' => '   ', 'password' => ' keep '])->getData(true);

    expect($data)->toBe(['name' => 'Neo', 'bio' => null, 'password' => ' keep ']);
});

test('ValidatePostSize answers 413 when Content-Length exceeds the limit', function () {
    config(['http.max_body_size' => 100]);

    $c = new TestClient();
    $c->container->instance(ValidatePostSize::class, new ValidatePostSize());
    $c->registry->global([ValidatePostSize::class]);
    $c->router->post('/up', fn () => 'ok');

    $big = $c->call('POST', '/up', [], ['Content-Length' => '5000']);
    $small = $c->call('POST', '/up', [], ['Content-Length' => '50']);

    config(['http.max_body_size' => null]);

    expect($big->status())->toBe(413)
        ->and($small->status())->toBe(200);
});

test('Signed URLs reject tampering and expiry', function () {
    $c = new TestClient();
    $c->router->get('/verify/{user}', fn () => 'verified')->name('verify')->middleware([new ValidateSignature($c->url)]);

    $url = $c->url->signedRoute('verify', ['user' => 5]);
    $path = fn (string $u): string => substr($u, strlen('http://localhost'));

    $expired = $c->url->temporarySignedRoute('verify', -10, ['user' => 5]);
    $fresh = $c->url->temporarySignedRoute('verify', 600, ['user' => 5]);

    expect($c->get($path($url))->status())->toBe(200)
        ->and($c->get($path($fresh))->status())->toBe(200)
        ->and($c->get($path($expired))->status())->toBe(403)
        ->and($c->get('/verify/5')->status())->toBe(403)
        ->and($c->get(str_replace('/verify/5', '/verify/6', $path($url)))->status())->toBe(403)
        ->and($c->get($path($url) . '&extra=1')->status())->toBe(403)
        ->and($c->get(preg_replace('/signature=[^&]+/', 'signature=deadbeef', $path($url)))->status())->toBe(403);
});

test('Session cookie follows the request scheme: Secure over HTTPS, not over HTTP', function () {
    $c = new TestClient();
    $c->router->get('/s', fn () => 'ok')->middleware($c->webStack());

    $http = $c->get('/s');
    $c->jar = [];
    $https = $c->get('https://localhost/s');

    $find = function (Response $r): CookieValue {
        foreach ($r->cookies() as $cookie) {
            if ($cookie->name === 'neo_session') {
                return $cookie;
            }
        }
        throw new RuntimeException('no session cookie');
    };

    expect($find($http)->secure)->toBeFalse()
        ->and($find($https)->secure)->toBeTrue();
});
