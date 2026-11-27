<?php

declare(strict_types=1);

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Network\Http\Request;
use Neo\Network\Http\Testing\TestClient;
use Neo\Security\Authentication\AuthManager;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authorization\AuthorizationException;
use Neo\Security\Authorization\Gate\AuthorizationResponse;
use Neo\Security\Authorization\Gate\GateManager;
use Neo\Security\Authorization\Middleware\Authorize;
use Neo\Security\Authorization\Middleware\PermissionMiddleware;
use Neo\Security\Authorization\Middleware\RoleMiddleware;
use Neo\Security\Authorization\Permission\PermissionRegistrar;

final class FakeUser implements Authenticatable
{
    public function __construct(public int $id, public array $roles = [], public array $permissions = []) {}

    public function getAuthIdentifier(): int|string|null { return $this->id; }

    public function getAuthPassword(): string { return ''; }

    public function hasRole(string $roles): bool { return array_intersect(explode('|', $roles), $this->roles) !== []; }

    public function hasAnyPermission(string $permissions): bool
    {
        foreach (explode('|', $permissions) as $p) {
            if (PermissionRegistrar::anyGrants($this->permissions, $p)) {
                return true;
            }
        }

        return false;
    }
}

final class FakePost
{
    public function __construct(public int $ownerId) {}
}

final class FakePostPolicy
{
    public function update(FakeUser $user, FakePost $post): bool|AuthorizationResponse
    {
        return $user->id === $post->ownerId ? true : AuthorizationResponse::deny('Чужой пост.');
    }

    public function create(FakeUser $user): bool { return in_array('author', $user->roles, true); }

    public function view(?FakeUser $user, FakePost $post): bool { return true; }
}

function fakeGate(?FakeUser $user): GateManager
{
    return new GateManager(new ServiceContainer(), static fn (): ?Authenticatable => $user, 'Nothing\\');
}

describe('Permission matching', function () {
    it('matches exact, prefix wildcard and star', function () {
        expect(PermissionRegistrar::grants('post.read', 'post.read'))->toBeTrue()
            ->and(PermissionRegistrar::grants('post.*', 'post.read'))->toBeTrue()
            ->and(PermissionRegistrar::grants('*', 'anything'))->toBeTrue()
            ->and(PermissionRegistrar::grants('post.*', 'page.read'))->toBeFalse()
            ->and(PermissionRegistrar::grants('post.read', 'post.update'))->toBeFalse();
    });

    it('never treats the requested name as a wildcard', function () {
        expect(PermissionRegistrar::grants('post.read', 'post.*'))->toBeFalse();
    });

    it('splits names from pipes, arrays and nesting', function () {
        expect(PermissionRegistrar::names(['a|b', ['c'], ' d ']))->toBe(['a', 'b', 'c', 'd']);
    });
});

describe('Gate', function () {
    it('denies by default when nothing is defined', function () {
        expect(fakeGate(new FakeUser(1))->allows('whatever'))->toBeFalse();
    });

    it('runs defined abilities with the user and arguments', function () {
        $gate = fakeGate(new FakeUser(1, ['editor']))
            ->define('edit', fn (FakeUser $u, int $n) => in_array('editor', $u->roles, true) && $n > 0);

        expect($gate->allows('edit', 5))->toBeTrue()
            ->and($gate->denies('edit', 0))->toBeTrue();
    });

    it('keeps guests out unless the first argument is nullable', function () {
        $gate = fakeGate(null)
            ->define('strict', fn (FakeUser $u) => true)
            ->define('open', fn (?FakeUser $u) => true);

        expect($gate->allows('strict'))->toBeFalse()
            ->and($gate->allows('open'))->toBeTrue();
    });

    it('lets before() override everything and null falls through', function () {
        $gate = fakeGate(new FakeUser(1, ['root']))
            ->define('x', fn (FakeUser $u) => false)
            ->before(fn (FakeUser $u) => in_array('root', $u->roles, true) ? true : null);

        expect($gate->allows('x'))->toBeTrue();

        $plain = fakeGate(new FakeUser(2))->define('x', fn (FakeUser $u) => false)
            ->before(fn (FakeUser $u) => in_array('root', $u->roles, true) ? true : null);

        expect($plain->allows('x'))->toBeFalse();
    });

    it('resolves policies for instances and classes', function () {
        $gate = fakeGate(new FakeUser(1, ['author']))->policy(FakePost::class, FakePostPolicy::class);

        expect($gate->allows('update', new FakePost(1)))->toBeTrue()
            ->and($gate->allows('update', new FakePost(2)))->toBeFalse()
            ->and($gate->allows('create', FakePost::class))->toBeTrue()
            ->and(fakeGate(new FakeUser(3))->policy(FakePost::class, FakePostPolicy::class)->allows('create', FakePost::class))->toBeFalse();
    });

    it('lets policy methods with a nullable user serve guests', function () {
        $gate = fakeGate(null)->policy(FakePost::class, FakePostPolicy::class);

        expect($gate->allows('view', new FakePost(1)))->toBeTrue()
            ->and($gate->allows('update', new FakePost(1)))->toBeFalse();
    });

    it('carries the denial message and status through authorize()', function () {
        $gate = fakeGate(new FakeUser(1))->policy(FakePost::class, FakePostPolicy::class);

        expect($gate->inspect('update', new FakePost(2))->message())->toBe('Чужой пост.')
            ->and(fn () => $gate->authorize('update', new FakePost(2)))->toThrow(AuthorizationException::class, 'Чужой пост.');
    });

    it('answers for another user with forUser()', function () {
        $gate = fakeGate(new FakeUser(1))->policy(FakePost::class, FakePostPolicy::class);

        expect($gate->forUser(new FakeUser(7))->allows('update', new FakePost(7)))->toBeTrue()
            ->and($gate->allows('update', new FakePost(7)))->toBeFalse();
    });

    it('does not autoload arbitrary strings passed as arguments', function () {
        expect(fakeGate(new FakeUser(1))->allows('x', '../../etc/passwd'))->toBeFalse();
    });
});

describe('Middleware', function () {
    function authClient(): TestClient
    {
        $c = new TestClient();

        $auth = new AuthManager($c->container, [
            'defaults' => ['guard' => 'api'],
            'guards' => ['api' => ['driver' => 'request', 'provider' => 'users']],
            'providers' => ['users' => ['driver' => 'model', 'model' => FakeUser::class]],
        ]);

        $auth->viaRequest('api', function (Request $request) {
            return match ($request->header('X-User')) {
                'admin' => new FakeUser(1, ['admin'], ['post.*']),
                'reader' => new FakeUser(2, [], ['post.read']),
                default => null,
            };
        });

        $gate = new GateManager($c->container, static fn () => $auth->guard()->user(), 'Nothing\\');
        $gate->define('panel', fn (FakeUser $u) => in_array('admin', $u->roles, true));

        $c->container->instance(AuthManager::class, $auth);
        $c->container->instance(GateManager::class, $gate);
        $c->registry->register([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'can' => Authorize::class,
        ]);

        $c->router->get('/by-role', fn () => 'ok')->middleware('role:admin|moderator');
        $c->router->get('/by-permission', fn () => 'ok')->middleware('permission:post.update');
        $c->router->get('/by-gate', fn () => 'ok')->middleware('can:panel');

        return $c;
    }

    it('answers 401 for guests and 403 for users without the role', function () {
        $c = authClient();

        expect($c->get('/by-role')->status())->toBe(401)
            ->and($c->get('/by-role', [], ['X-User' => 'reader'])->status())->toBe(403)
            ->and($c->get('/by-role', [], ['X-User' => 'admin'])->status())->toBe(200);
    });

    it('checks permissions including wildcards', function () {
        $c = authClient();

        expect($c->get('/by-permission', [], ['X-User' => 'reader'])->status())->toBe(403)
            ->and($c->get('/by-permission', [], ['X-User' => 'admin'])->status())->toBe(200);
    });

    it('runs the can middleware through the Gate', function () {
        $c = authClient();

        expect($c->get('/by-gate')->status())->toBe(403)
            ->and($c->get('/by-gate', [], ['X-User' => 'reader'])->status())->toBe(403)
            ->and($c->get('/by-gate', [], ['X-User' => 'admin'])->status())->toBe(200);
    });
});
