<?php

declare(strict_types=1);

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Pipeline\Pipeline;
use Neo\Information\Validator\ValidationException;
use Neo\Network\Http\Context\RequestContext;
use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\MiddlewareRegistry;
use Neo\Network\Http\Testing\ArrayResponse;
use Neo\Network\Http\Testing\TestClient;

final class KernelTestTerminable
{
    public static array $terminated = [];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        self::$terminated[] = $response->status();
    }
}

test('Pipeline runs layers in order, supports parameters and closures', function () {
    $container = new ServiceContainer();
    $trace = [];

    $result = (new Pipeline($container))
        ->send('x')
        ->through([
            function ($v, $next) use (&$trace) {
                $trace[] = 'in1';

                return $next($v . '1') . 'o1';
            },
            function ($v, $next) use (&$trace) {
                $trace[] = 'in2';

                return $next($v . '2') . 'o2';
            },
        ])
        ->then(fn ($v) => '[' . $v . ']');

    expect($result)->toBe('[x12]o2o1')
        ->and($trace)->toBe(['in1', 'in2']);
});

test('Kernel turns exceptions into responses INSIDE the pipeline so outer middleware still run', function () {
    $c = new TestClient();
    $seen = null;

    $c->router->get('/boom', function () {
        throw new HttpException(418, 'teapot');
    })->middleware([function (Request $r, Closure $next) use (&$seen) {
        $response = $next($r);
        $seen = $response->status();

        return $response;
    }]);

    $response = $c->get('/boom');

    expect($response->status())->toBe(418)
        ->and($seen)->toBe(418);
});

test('Kernel answers JSON errors for JSON clients and HTML for browsers', function () {
    $c = new TestClient();
    $c->router->get('/missing-model', fn () => abort(404));

    $json = $c->get('/missing-model', [], ['Accept' => 'application/json']);
    $html = $c->get('/missing-model', [], ['Accept' => 'text/html']);

    expect($json->status())->toBe(404)
        ->and($json->headers()->get('Content-Type'))->toBe('application/json')
        ->and($json->getData(true)['message'])->toBe('Not Found')
        ->and($html->headers()->get('Content-Type'))->toContain('text/html')
        ->and($html->getContent())->toContain('404');
});

test('Kernel hides internal error details outside debug mode', function () {
    $c = new TestClient();
    $c->router->get('/fail', fn () => throw new RuntimeException('SQLSTATE secret table name'));

    $response = $c->get('/fail', [], ['Accept' => 'application/json']);

    expect($response->status())->toBe(500)
        ->and($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->getContent())->not->toContain('trace')
        ->and($response->getData(true)['message'])->toBe('Server Error')
        ->and(count($c->handler->reported))->toBe(1);
});

test('Kernel does not report client errors but reports server errors', function () {
    $c = new TestClient();
    $c->router->get('/a', fn () => abort(404));
    $c->router->get('/b', fn () => abort(500, 'x'));

    $c->get('/a');
    $c->get('/b');

    expect(array_map(fn ($e) => $e::class, $c->handler->reported))->toBe([HttpException::class]);
});

test('Kernel escapes exception text in HTML error pages', function () {
    $c = new TestClient();
    $c->router->get('/x', fn () => abort(400, '<script>alert(1)</script>'));

    $response = $c->get('/x', [], ['Accept' => 'text/html']);

    expect($response->getContent())->not->toContain('<script>alert')
        ->and($response->getContent())->toContain('&lt;script&gt;');
});

test('Kernel merges cookies queued in the context into the response even without the cookie middleware', function () {
    $c = new TestClient();
    $c->router->get('/c', function () use ($c) {
        $c->container->make(Neo\Network\Cookie\CookieManager::class)->set('x', '1', 5);

        return 'ok';
    });

    $response = $c->get('/c');

    expect($response->cookies())->toHaveKey('x|/|')
        ->and($c->jar)->toHaveKey('x');
});

test('Kernel runs terminate() on global and route middleware after the response', function () {
    $c = new TestClient();
    KernelTestTerminable::$terminated = [];

    $c->registry->global([KernelTestTerminable::class]);
    $c->router->get('/t', fn () => 'ok');
    $c->get('/t');

    expect(KernelTestTerminable::$terminated)->toBe([200]);
});

test('MiddlewareRegistry expands groups and aliases and refuses unknown names', function () {
    $registry = new MiddlewareRegistry();
    $registry->register('a', KernelTestTerminable::class);
    $registry->group('g', ['a', 'throttle:5,1' === 'x' ? '' : KernelTestTerminable::class]);
    $registry->register('thr', 'Neo\\Network\\Http\\Routing\\Middleware\\ThrottleRequests');

    expect($registry->resolve(['a']))->toBe([KernelTestTerminable::class])
        ->and($registry->resolve(['thr:60,1']))->toBe(['Neo\\Network\\Http\\Routing\\Middleware\\ThrottleRequests:60,1'])
        ->and($registry->resolve(['g']))->toBe([KernelTestTerminable::class])
        ->and(fn () => $registry->resolve(['auth']))->toThrow(InvalidArgumentException::class, 'auth');
});

test('MiddlewareRegistry applies priority and withoutMiddleware', function () {
    $registry = new MiddlewareRegistry();
    $registry->register(['first' => 'Neo\\A', 'second' => 'Neo\\B', 'third' => 'Neo\\C']);
    $registry->priority(['Neo\\A', 'Neo\\B']);

    expect($registry->resolve(['second', 'third', 'first']))->toBe(['Neo\\A', 'Neo\\C', 'Neo\\B'])
        ->and($registry->resolve(['first', 'second', 'third'], ['second']))->toBe(['Neo\\A', 'Neo\\C']);
});

test('A middleware that is not registered fails the request loudly instead of being skipped', function () {
    $c = new TestClient();
    $c->router->get('/secure', fn () => 'data')->middleware('auth');

    $response = $c->get('/secure');

    expect($response->status())->toBe(500)
        ->and($response->getContent())->not->toContain('data');
});

test('RequestContext isolates state between fibers and cleans up on leave', function () {
    $context = new RequestContext();
    $main = new Request();
    $context->enter($main, new ArrayResponse());
    $context->set('k', 'main');

    $inner = null;
    $fiber = new Fiber(function () use ($context, &$inner) {
        $context->enter(new Request(), new ArrayResponse());
        $context->set('k', 'fiber');
        Fiber::suspend();
        $inner = $context->get('k');
        $context->leave();
    });
    $fiber->start();

    expect($context->get('k'))->toBe('main');

    $fiber->resume();

    expect($inner)->toBe('fiber')
        ->and($context->get('k'))->toBe('main')
        ->and($context->request())->toBe($main);

    $context->leave();

    expect($context->isEntered())->toBeFalse();
});

test('Container scoped services live per request, not per process', function () {
    $container = new ServiceContainer();
    $context = new RequestContext();
    $container->useScope($context);
    $container->scoped(stdClass::class, fn () => new stdClass());

    $outside1 = $container->make(stdClass::class);
    $outside2 = $container->make(stdClass::class);

    $context->enter(new Request(), new ArrayResponse());
    $a1 = $container->make(stdClass::class);
    $a2 = $container->make(stdClass::class);
    $context->leave();

    $context->enter(new Request(), new ArrayResponse());
    $b = $container->make(stdClass::class);
    $context->leave();

    expect($outside1)->toBe($outside2)
        ->and($a1)->toBe($a2)
        ->and($a1)->not->toBe($b)
        ->and($a1)->not->toBe($outside1);
});

test('Handler sends only the headline of a ValidationException to clients, errors go in a separate field', function () {
    $c = new TestClient();
    $c->router->post('/v', function () {
        throw new ValidationException(['email' => ['Неверный email.'], 'name' => ['Обязательно.']]);
    });

    $data = $c->json('POST', '/v')->getData(true);

    expect($data['message'])->toBe('Данные не прошли проверку.')
        ->and($data['message'])->not->toContain('email')
        ->and($data['errors'])->toBe(['email' => ['Неверный email.'], 'name' => ['Обязательно.']]);
});
