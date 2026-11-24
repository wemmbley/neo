<?php

declare(strict_types=1);

use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\FormRequest;
use Neo\Network\Http\Request;
use Neo\Network\Http\Routing\Controllers\HasMiddleware;
use Neo\Network\Http\Routing\Controllers\Middleware;
use Neo\Network\Http\Routing\Route;
use Neo\Network\Http\Testing\TestClient;

enum RouterTestSuit: string
{
    case Hearts = 'hearts';
    case Spades = 'spades';
}

final class RouterTestController
{
    public function index(): string
    {
        return 'index';
    }

    public function show(Request $request, int $id): array
    {
        return ['id' => $id, 'path' => $request->path()];
    }

    public function __invoke(): string
    {
        return 'invoked';
    }
}

final class RouterTestStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string', 'extra' => 'string'];
    }
}

final class RouterTestGuardedController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(fn (Request $r, Closure $next) => response_text('blocked', 403), only: ['secret'])];
    }

    public function open(): string
    {
        return 'open';
    }

    public function secret(): string
    {
        return 'secret';
    }
}

function response_text(string $text, int $status = 200): Neo\Network\Http\Response
{
    return new Neo\Network\Http\Response($text, $status);
}

test('Router matches static and dynamic routes with typed parameters', function () {
    $c = new TestClient();
    $c->router->get('/hello', fn () => 'hi');
    $c->router->get('/users/{id}', fn (int $id) => ['id' => $id, 'type' => gettype($id)]);

    expect($c->get('/hello')->getContent())->toBe('hi')
        ->and($c->get('/users/42')->getData(true))->toBe(['id' => 42, 'type' => 'integer']);
});

test('Router returns 404 for unknown paths and for non numeric typed parameters', function () {
    $c = new TestClient();
    $c->router->get('/users/{id}', fn (int $id) => 'ok');

    expect($c->get('/nope')->status())->toBe(404)
        ->and($c->get('/users/abc')->status())->toBe(404);
});

test('Router returns 405 with an Allow header', function () {
    $c = new TestClient();
    $c->router->get('/only-get', fn () => 'ok');
    $c->router->post('/only-get', fn () => 'ok');

    $response = $c->call('DELETE', '/only-get');

    expect($response->status())->toBe(405)
        ->and($response->headers()->get('Allow'))->toContain('GET')
        ->and($response->headers()->get('Allow'))->toContain('POST');
});

test('Router answers OPTIONS with the allowed methods when no CORS handles it', function () {
    $c = new TestClient();
    $c->router->get('/res', fn () => 'ok');

    $response = $c->call('OPTIONS', '/res');

    expect($response->status())->toBe(200)
        ->and($response->headers()->get('Allow'))->toContain('GET');
});

test('Router serves HEAD from GET routes without a body', function () {
    $c = new TestClient();
    $c->router->get('/page', fn () => 'body');

    $response = $c->call('HEAD', '/page');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toBe('')
        ->and($response->headers()->get('Content-Length'))->toBe('4');
});

test('Router supports optional parameters, defaults and where constraints', function () {
    $c = new TestClient();
    $c->router->get('/posts/{slug?}', fn (?string $slug = null) => $slug ?? 'all');
    $c->router->get('/n/{n}', fn (string $n) => $n)->whereNumber('n');
    $c->router->get('/c/{color}', fn (string $color) => $color)->whereIn('color', ['red', 'blue']);

    expect($c->get('/posts')->getContent())->toBe('all')
        ->and($c->get('/posts/hello')->getContent())->toBe('hello')
        ->and($c->get('/n/5')->status())->toBe(200)
        ->and($c->get('/n/x')->status())->toBe(404)
        ->and($c->get('/c/red')->status())->toBe(200)
        ->and($c->get('/c/green')->status())->toBe(404);
});

test('Router global patterns apply to matching parameters', function () {
    $c = new TestClient();
    $c->router->pattern('id', '[0-9]+');
    $c->router->get('/items/{id}', fn (string $id) => $id);

    expect($c->get('/items/7')->status())->toBe(200)
        ->and($c->get('/items/seven')->status())->toBe(404);
});

test('Router groups combine prefix, name and middleware', function () {
    $c = new TestClient();
    $log = [];

    $c->router->prefix('admin')->name('admin.')->middleware([function (Request $r, Closure $next) use (&$log) {
        $log[] = 'group';

        return $next($r);
    }])->group(function () use ($c) {
        $c->router->get('/users', fn () => 'users')->name('users');
        $c->router->prefix('v2')->group(fn () => $c->router->get('/ping', fn () => 'pong')->name('ping'));
    });

    expect($c->get('/admin/users')->getContent())->toBe('users')
        ->and($c->get('/admin/v2/ping')->getContent())->toBe('pong')
        ->and($log)->toBe(['group', 'group'])
        ->and($c->router->has('admin.users'))->toBeTrue()
        ->and($c->router->has('admin.ping'))->toBeTrue();
});

test('Router controller groups and invokable controllers', function () {
    $c = new TestClient();
    $c->router->controller(RouterTestController::class)->group(function () use ($c) {
        $c->router->get('/c/index', 'index');
        $c->router->get('/c/{id}', 'show');
    });
    $c->router->get('/invoke', RouterTestController::class);
    $c->router->get('/arr', [RouterTestController::class, 'index']);

    expect($c->get('/c/index')->getContent())->toBe('index')
        ->and($c->get('/c/9')->getData(true))->toBe(['id' => 9, 'path' => 'c/9'])
        ->and($c->get('/invoke')->getContent())->toBe('invoked')
        ->and($c->get('/arr')->getContent())->toBe('index');
});

test('Router resource registers RESTful routes with names', function () {
    $c = new TestClient();
    $c->router->resource('photos', RouterTestController::class)->only(['index', 'show']);
    $c->router->apiResource('tags', RouterTestController::class);
    $c->router->resource('posts.comments', RouterTestController::class)->only(['show']);

    $names = array_map(fn (Route $r) => $r->getName(), $c->router->getRoutes()->getRoutes());
    $uris = array_map(fn (Route $r) => $r->uri(), $c->router->getRoutes()->getRoutes());

    expect($names)->toContain('photos.index')
        ->and($names)->toContain('photos.show')
        ->and($names)->not->toContain('photos.create')
        ->and($names)->toContain('tags.update')
        ->and($names)->not->toContain('tags.edit')
        ->and($uris)->toContain('/photos/{photo}')
        ->and($uris)->toContain('/posts/{post}/comments/{comment}');
});

test('Router redirect, view-less fallback and permanent redirects', function () {
    $c = new TestClient();
    $c->router->redirect('/old/{id}', '/new/{id}');
    $c->router->permanentRedirect('/moved', '/target');
    $c->router->fallback(fn () => response_text('custom 404', 404));

    $old = $c->get('/old/5');

    expect($old->status())->toBe(302)
        ->and($old->headers()->get('Location'))->toBe('/new/5')
        ->and($c->get('/moved')->status())->toBe(301)
        ->and($c->get('/anything/else')->getContent())->toBe('custom 404');
});

test('Router converts return values to responses', function () {
    $c = new TestClient();
    $c->router->get('/arr', fn () => ['a' => 1]);
    $c->router->get('/null', fn () => null);
    $c->router->get('/int', fn () => 5);
    $c->router->get('/obj', fn () => new class implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['j' => true];
        }
    });
    $c->router->get('/bad', fn () => new stdClass());

    expect($c->get('/arr')->headers()->get('Content-Type'))->toBe('application/json')
        ->and($c->get('/null')->getContent())->toBe('')
        ->and($c->get('/int')->getContent())->toBe('5')
        ->and($c->get('/obj')->getContent())->toBe('{"j":true}')
        ->and($c->get('/bad')->status())->toBe(500);
});

test('Router builds named URLs with parameters, optional parts and query strings', function () {
    $c = new TestClient();
    $c->router->get('/users/{user}/posts/{post?}', fn () => '')->name('user.posts');

    expect($c->url->route('user.posts', ['user' => 5], false))->toBe('/users/5/posts')
        ->and($c->url->route('user.posts', ['user' => 5, 'post' => 'a b', 'page' => 2], false))->toBe('/users/5/posts/a%20b?page=2')
        ->and($c->url->route('user.posts', [7, 8], false))->toBe('/users/7/posts/8')
        ->and(fn () => $c->url->route('user.posts', [], false))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->url->route('nope'))->toThrow(InvalidArgumentException::class);
});

test('Router keeps route parameters isolated between requests handled at the same time', function () {
    $c = new TestClient();
    $seen = [];
    $fibers = [];

    $c->router->get('/slow/{id}', function (string $id, Request $request) use (&$seen) {
        Fiber::suspend();
        $seen[$id] = $request->route('id');

        return $id;
    });

    foreach (['a', 'b', 'c'] as $id) {
        $fibers[$id] = new Fiber(fn () => $c->get('/slow/' . $id));
        $fibers[$id]->start();
    }

    foreach ($fibers as $fiber) {
        $fiber->resume();
    }

    expect($seen)->toBe(['a' => 'a', 'b' => 'b', 'c' => 'c']);
});

test('Router resolves backed enum parameters and answers 404 for unknown cases', function () {
    $c = new TestClient();
    $c->router->get('/suit/{suit}', fn (RouterTestSuit $suit) => $suit->name);

    expect($c->get('/suit/hearts')->getContent())->toBe('Hearts')
        ->and($c->get('/suit/clubs')->status())->toBe(404);
});

test('Router uses explicit bindings and 404s when the binder returns null', function () {
    $c = new TestClient();
    $c->router->bind('user', fn (string $v) => $v === '1' ? ['name' => 'Neo'] : null);
    $c->router->get('/u/{user}', fn (array $user) => $user['name']);

    expect($c->get('/u/1')->getContent())->toBe('Neo')
        ->and($c->get('/u/2')->status())->toBe(404);
});

test('FormRequest validates before the controller and returns 422 JSON', function () {
    $c = new TestClient();
    $called = false;
    $c->router->post('/posts', function (RouterTestStoreRequest $request) use (&$called) {
        $called = true;

        return $request->validated();
    });

    $bad = $c->json('POST', '/posts', ['extra' => 'x']);
    $good = $c->json('POST', '/posts', ['title' => 'Hi', 'extra' => 'x', 'unlisted' => 'y']);

    expect($bad->status())->toBe(422)
        ->and($bad->getData(true)['errors'])->toHaveKey('title')
        ->and($good->status())->toBe(200)
        ->and($good->getData(true))->toBe(['title' => 'Hi', 'extra' => 'x'])
        ->and($called)->toBeTrue();
});

test('Controller middleware declared via HasMiddleware honours only/except', function () {
    $c = new TestClient();
    $c->router->get('/open', [RouterTestGuardedController::class, 'open']);
    $c->router->get('/secret', [RouterTestGuardedController::class, 'secret']);

    expect($c->get('/open')->getContent())->toBe('open')
        ->and($c->get('/secret')->status())->toBe(403);
});

test('Router::current exposes the matched route only inside the request', function () {
    $c = new TestClient();
    $inside = null;
    $c->router->get('/me', function () use ($c, &$inside) {
        $inside = $c->router->currentRouteName();

        return 'ok';
    })->name('me');

    $c->get('/me');

    expect($inside)->toBe('me')
        ->and($c->router->current())->toBeNull();
});
