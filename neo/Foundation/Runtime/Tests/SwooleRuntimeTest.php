<?php

declare(strict_types=1);

# Эти тесты работают на заглушке Swoole. С настоящим расширением заглушка не подключается и тесты пропускаются.
if (extension_loaded('swoole') || extension_loaded('openswoole')) {
    return;
}

require_once __DIR__ . '/Support/FakeSwoole.php';

use Neo\Foundation\Runtime\Context;
use Neo\Foundation\Runtime\Exceptions\PoolExhaustedException;
use Neo\Foundation\Runtime\Lifecycle;
use Neo\Foundation\Runtime\Runtime;
use Neo\Foundation\Runtime\Swoole\SwooleApi;
use Neo\Foundation\Runtime\Pool\Pool;
use Neo\Foundation\Runtime\Pool\PoolOptions;
use Neo\Foundation\Runtime\Swoole\SwooleRuntime;
use Neo\Foundation\Runtime\Swoole\SwooleTaskDispatcher;
use Neo\Foundation\Runtime\Sync\SyncRuntime;
use Neo\Foundation\Runtime\Tasks\Task;
use Swoole\Timer;
use Neo\Information\Lang\LangProcessor;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Server\SwooleServer;
use Neo\Network\Http\Testing\TestClient;
use Swoole\Coroutine;

function co(callable $fn): void
{
    Coroutine::create($fn);
}

beforeEach(function () {
    Runtime::use(new SwooleRuntime());
});

afterEach(function () {
    Runtime::use(null);
});

test('SwooleApi sees the Swoole flavor and resolves constants', function () {
    define('OPENSWOOLE_FAKE_FLAG', 7);

    expect(SwooleApi::flavor())->toBe('Swoole')
        ->and(SwooleApi::cls('Http\Server'))->toBe('\Swoole\Http\Server')
        ->and(SwooleApi::constant('FAKE_FLAG'))->toBe(7)
        ->and(SwooleApi::constant('HOOK_TCP'))->toBe(SWOOLE_HOOK_TCP)
        ->and(SwooleApi::inCoroutine())->toBeFalse();
});

test('Runtime auto-detects Swoole inside a coroutine without explicit use()', function () {
    Runtime::use(null);
    $inside = null;

    co(function () use (&$inside) { $inside = Runtime::current()->name(); });

    expect($inside)->toBe('swoole')->and(Runtime::current()->name())->toBe('sync');
});

test('coroutine contexts are isolated and children reach their parent explicitly', function () {
    $seen = [];

    co(function () use (&$seen) {
        Context::set('user', 'alice');

        co(function () use (&$seen) {
            $seen['child own'] = Context::get('user', 'none');
            $seen['child inherited'] = Context::inherited('user', 'none');
            Context::copyFromParent('user');
            $seen['child copied'] = Context::get('user', 'none');
        });

        co(function () use (&$seen) { Context::set('user', 'bob'); });

        $seen['parent after siblings'] = Context::get('user');
    });

    expect($seen)->toBe([
        'child own' => 'none',
        'child inherited' => 'alice',
        'child copied' => 'alice',
        'parent after siblings' => 'alice',
    ]);
});

test('defer runs at coroutine end in LIFO order', function () {
    $order = [];

    co(function () use (&$order) {
        Runtime::current()->defer(function () use (&$order) { $order[] = 'first'; });
        Runtime::current()->defer(function () use (&$order) { $order[] = 'second'; });
        $order[] = 'body';
    });

    expect($order)->toBe(['body', 'second', 'first']);
});

test('Swoole AsyncIO returns results, keeps keys and surfaces errors', function () {
    $async = Runtime::current()->async();
    $out = null;
    $err = null;

    co(function () use ($async, &$out, &$err) {
        $out = $async->all(['a' => fn () => 1, 'b' => fn () => 2]);

        try {
            $async->all(['ok' => fn () => 1, 'bad' => fn () => throw new LogicException('x')]);
        } catch (LogicException $e) {
            $err = $e->getMessage();
        }
    });

    expect($out)->toBe(['a' => 1, 'b' => 2])->and($err)->toBe('x');
});

test('RequestContext keeps simultaneous requests apart', function () {
    $c = new TestClient();
    $seen = [];

    co(function () use ($c, &$seen) {
        $c->context->enter(Request::create('/a'), new Response());

        co(function () use ($c, &$seen) {
            $seen['task inside request'] = $c->context->isEntered();
        });

        co(function () use ($c, &$seen) {
            $seen['sibling task of the same request'] = $c->context->isEntered();
        });

        $seen['own'] = $c->context->isEntered();
        $c->context->leave();
        $seen['after leave'] = $c->context->isEntered();
    });

    expect($seen)->toBe([
        'task inside request' => true,
        'sibling task of the same request' => true, # дочерняя корутина запроса видит родителя
        'own' => true,
        'after leave' => false,
    ]);
});

test('Lang locale set in one coroutine does not leak into another', function () {
    $lang = new LangProcessor('en', 'en');
    $seen = [];

    co(function () use ($lang, &$seen) {
        $lang->setLocale('ru');
        $seen['one'] = $lang->getLocale();
    });
    co(function () use ($lang, &$seen) { $seen['two'] = $lang->getLocale(); });

    expect($seen)->toBe(['one' => 'ru', 'two' => 'en'])->and($lang->getLocale())->toBe('en');
});

function poolManager(int $max = 2, ?string &$file = null): DatabaseManager
{
    $file = sys_get_temp_dir() . '/neo-pool-' . uniqid() . '.sqlite';

    return new DatabaseManager([
        'default' => 'main',
        'pool' => ['min' => 0, 'max' => $max, 'acquire_timeout' => 0.01],
        'connections' => ['main' => ['driver' => 'sqlite', 'database' => $file]],
    ]);
}

test('DB pool: one connection per coroutine, returned at the end, transaction rolled back', function () {
    $db = poolManager(2, $file);
    $ids = [];

    co(function () use ($db, &$ids) {
        $one = $db->connection();
        $ids['same in coroutine'] = $one === $db->connection();

        co(function () use ($db, $one, &$ids) {
            $ids['other coroutine gets another connection'] = $db->connection() !== $one;
        });

        $one->beginTransaction();
        $one->statement('CREATE TABLE t (id INTEGER)');
    });

    $stats = $db->poolStats()['main'];
    expect($ids)->toBe(['same in coroutine' => true, 'other coroutine gets another connection' => true])
        ->and($stats['leased'])->toBe(0)
        ->and($stats['idle'])->toBe(2);

    co(function () use ($db) {
        $c = $db->connection();
        expect($c->transactionLevel())->toBe(0);
        expect(fn () => $c->select('SELECT * FROM t'))->toThrow(Throwable::class); # CREATE откатился вместе с транзакцией
    });

    @unlink($file);
});

test('DB pool: exhaustion throws, releaseCurrent is idempotent', function () {
    $db = poolManager(1, $file);
    $threw = false;

    co(function () use ($db, &$threw) {
        $db->connection();

        co(function () use ($db, &$threw) {
            try { $db->connection(); } catch (PoolExhaustedException) { $threw = true; }
        });

        $db->releaseCurrent();
        $db->releaseCurrent();
    });

    expect($threw)->toBeTrue()->and($db->poolStats()['main']['leased'])->toBe(0);
    @unlink($file);
});

test('DB outside a coroutine stays on a single plain connection even under Swoole', function () {
    $db = poolManager(1, $file);

    expect($db->connection())->toBe($db->connection())->and($db->poolStats())->toBe([]);
    @unlink($file);
});

test('DB global listener reaches pooled connections after reset', function () {
    $db = poolManager(1, $file);
    $queries = 0;
    $db->listen(function () use (&$queries) { $queries++; });

    foreach ([1, 2] as $_) {
        co(function () use ($db) { $db->connection()->select('SELECT 1'); });
    }

    expect($queries)->toBe(2);
    @unlink($file);
});

test('SwooleServer handles a request, resets lifecycle and survives handler errors', function () {
    $c = new TestClient();
    $c->router->get('/hello', fn () => 'hi');
    $c->router->get('/boom', fn () => throw new RuntimeException('x'));

    $resets = 0;
    $life = new Lifecycle();
    $life->resettable(function () use (&$resets) { $resets++; });

    $server = new SwooleServer($c->kernel, ['workers' => 1], $life);
    $handle = new ReflectionMethod($server, 'handle');

    $fakeRequest = fn (string $uri) => new class($uri) {
        public array $server;
        public array $header = ['host' => 'localhost'];
        public array $get = [];
        public array $post = [];
        public array $cookie = [];
        public array $files = [];
        public function __construct(string $uri) { $this->server = ['request_method' => 'GET', 'request_uri' => $uri, 'query_string' => '']; }
        public function rawContent(): string { return ''; }
    };
    $fakeResponse = fn () => new class {
        public int $status = 0;
        public string $body = '';
        public function status(int $code, string $reason = ''): void { $this->status = $code; }
        public function header(string $n, string $v): void {}
        public function rawCookie(...$a): void {}
        public function end(string $b = ''): void { $this->body = $b; }
        public function isWritable(): bool { return true; }
    };

    $ok = $fakeResponse();
    $notFound = $fakeResponse();
    co(function () use ($handle, $server, $fakeRequest, $ok, $notFound) {
        $handle->invoke($server, $fakeRequest('/hello'), $ok, false);
        $handle->invoke($server, $fakeRequest('/missing'), $notFound, false);
    });

    expect($ok->status)->toBe(200)->and($ok->body)->toBe('hi')
        ->and($notFound->status)->toBe(404)
        ->and($resets)->toBe(2)
        ->and($server->settings()['hook_flags'] & SWOOLE_HOOK_FILE)->toBe(0)
        ->and($server->settings()['hook_flags'] & SWOOLE_HOOK_TCP)->toBe(SWOOLE_HOOK_TCP)
        ->and($server->settings()['enable_coroutine'])->toBeTrue();
});


final class RecordingTask implements Task
{
    public static array $runs = [];

    public function handle(array $payload): void
    {
        self::$runs[] = $payload;
    }
}

final class FailingTask implements Task
{
    public function handle(array $payload): void
    {
        throw new RuntimeException('task failed');
    }
}

test('Pool reaper closes idle resources on a timer and stops on close', function () {
    Timer::$ticks = [];
    $runtime = Runtime::current();
    $pool = new Pool(
        factory: fn () => new stdClass(),
        options: new PoolOptions(min: 0, max: 2, idleTimeout: 0.0, reapInterval: 5.0),
        runtime: $runtime,
    );

    $pool->startReaper();
    $pool->startReaper(); # второй запуск ничего не добавляет

    expect(Timer::$ticks)->toHaveCount(1);

    co(function () use ($pool) {
        $a = $pool->acquire();
        $pool->release($a);
    });
    usleep(2000);
    expect($pool->stats()['total'])->toBe(1);

    foreach (Timer::$ticks as $tick) { $tick(); }
    expect($pool->stats()['total'])->toBe(0);

    $pool->close();
    expect(Timer::$ticks)->toHaveCount(0);
});

test('Pool reaper is not started without an event loop or when disabled', function () {
    Timer::$ticks = [];
    $off = new Pool(fn () => new stdClass(), new PoolOptions(reapInterval: 0.0), Runtime::current());
    $off->startReaper();

    $sync = new Pool(fn () => new stdClass(), new PoolOptions(), new SyncRuntime());
    $sync->startReaper();

    expect(Timer::$ticks)->toHaveCount(0);
});

test('request timeout answers 504 and does not emit a late response', function () {
    $c = new TestClient();
    $c->router->get('/slow', fn () => 'late');

    $server = new SwooleServer($c->kernel, ['request_timeout' => 0.5]);
    $handle = new ReflectionMethod($server, 'handle');

    $request = new class {
        public array $server = ['request_method' => 'GET', 'request_uri' => '/slow', 'query_string' => ''];
        public array $header = ['host' => 'localhost'];
        public array $get = [];
        public array $post = [];
        public array $cookie = [];
        public array $files = [];
        public function rawContent(): string { return ''; }
    };
    $response = new class {
        public int $status = 0;
        public string $body = '';
        public function status(int $code, string $reason = ''): void { $this->status = $code; }
        public function header(string $n, string $v): void {}
        public function end(string $b = ''): void { $this->body = $b; }
        public function isWritable(): bool { return true; }
    };

    Coroutine::$hang = false;
    co(function () use ($handle, $server, $request, $response) {
        Coroutine::$hang = true; # дочерняя корутина запроса «зависает»
        $log = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/neo-timeout-test.log');
        $handle->invoke($server, $request, $response, false);
        ini_set('error_log', (string) $log);
        Coroutine::$hang = false;
    });

    expect($response->status)->toBe(504)->and($response->body)->toBe('Gateway Timeout');
});

test('tasks run inline in sync, in a coroutine under Swoole and in task workers when attached', function () {
    RecordingTask::$runs = [];

    (new SyncRuntime())->tasks()->dispatch(RecordingTask::class, ['via' => 'sync']);
    co(fn () => Runtime::current()->tasks()->dispatch(RecordingTask::class, ['via' => 'coroutine']));

    $sent = [];
    $server = new class($sent) {
        public function __construct(public array &$sent) {}
        public function task(array $data): int { $this->sent[] = $data; return 1; }
    };
    $runtime = new SwooleRuntime();
    $runtime->attachTaskServer($server);
    $runtime->tasks()->dispatch(RecordingTask::class, ['via' => 'worker']);

    expect(array_column(RecordingTask::$runs, 'via'))->toBe(['sync', 'coroutine'])
        ->and($runtime->tasks())->toBeInstanceOf(SwooleTaskDispatcher::class)
        ->and($sent)->toBe([['task' => RecordingTask::class, 'payload' => ['via' => 'worker']]]);

    # Сторона task worker: сервер получает данные и запускает задачу, ошибка задачи не роняет воркер.
    $life = new Lifecycle();
    $resets = 0;
    $life->resettable(function () use (&$resets) { $resets++; });
    $taskServer = new SwooleServer((new TestClient())->kernel, ['task_workers' => 2], $life);
    $run = new ReflectionMethod($taskServer, 'task');

    $makeTask = fn (array $data) => new class($data) {
        public bool $finished = false;
        public function __construct(public array $data) {}
        public function finish(mixed $r): void { $this->finished = true; }
    };

    $ok = $makeTask($sent[0]);
    $bad = $makeTask(['task' => FailingTask::class, 'payload' => []]);

    $log = ini_get('error_log');
    ini_set('error_log', sys_get_temp_dir() . '/neo-task-test.log');
    $run->invoke($taskServer, $ok);
    $run->invoke($taskServer, $bad);
    ini_set('error_log', (string) $log);

    expect(array_column(RecordingTask::$runs, 'via'))->toBe(['sync', 'coroutine', 'worker'])
        ->and($ok->finished)->toBeTrue()->and($bad->finished)->toBeTrue()
        ->and($resets)->toBe(2)
        ->and($taskServer->settings()['task_worker_num'])->toBe(2)
        ->and($taskServer->settings()['task_enable_coroutine'])->toBeTrue();
});
