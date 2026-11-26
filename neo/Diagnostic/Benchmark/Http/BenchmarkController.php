<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Http;

use Neo\Diagnostic\Benchmark\Scenarios\Registry;
use Neo\Diagnostic\Benchmark\Support\Context;
use Neo\Diagnostic\Benchmark\Support\NoDataException;
use Neo\Diagnostic\Benchmark\Support\Runner;
use Neo\Diagnostic\Benchmark\Support\Seeder;
use Neo\Foundation\Config\Config;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Network\Http\Request;
use Throwable;

/**
 * HTTP-вход нагрузочных тестов. Один запрос = один прогон сценария (то, что бьёт wrk),
 * /benchmark/all = пакетный прогон внутри процесса (без HTTP), чтобы отделить стоимость БД от стоимости сервера.
 */
final class BenchmarkController
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Runner $runner,
        private readonly Seeder $seeder,
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {}

    public function index(Request $request): mixed
    {
        $base = '/' . trim($request->path(), '/');
        $rows = '';

        foreach ($this->registry->all() as $s) {
            $rows .= sprintf(
                '<tr><td><a href="%1$s/%2$s">%2$s</a></td><td>%3$s</td><td>%4$s</td></tr>',
                htmlspecialchars($base), htmlspecialchars($s->name), htmlspecialchars($s->group), htmlspecialchars($s->description),
            );
        }

        $html = '<!doctype html><meta charset="utf-8"><title>Neo Benchmark</title>'
            . '<style>body{font:14px system-ui;margin:2rem;max-width:1100px}td,th{padding:4px 12px;text-align:left;border-bottom:1px solid #ddd}code{background:#eee;padding:2px 6px}</style>'
            . '<h1>Neo Benchmark</h1>'
            . '<p><a href="' . $base . '/stats">stats</a> · <a href="' . $base . '/seed">seed</a> (?users=1000&amp;posts=10000&amp;comments=50000) · '
            . '<a href="' . $base . '/reset">reset</a> · <a href="' . $base . '/all?format=text">all (in-process)</a></p>'
            . '<p>Потолок сервера без раннера и JSON: <a href="' . $base . '/raw">raw</a></p>'
            . '<p>Нагрузка: <code>wrk -t4 -c100 -d30s --latency http://127.0.0.1:8000' . $base . '/mix</code></p>'
            . '<table><tr><th>scenario</th><th>group</th><th>description</th></tr>' . $rows . '</table>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Самый дешёвый ответ: без раннера, статистики и JSON. Измеряет потолок сервера, роутера и middleware.
     * (Не путать с /benchmark/ping: тот идёт через раннер и отдаёт JSON с замерами.)
     */
    public function raw(): mixed
    {
        return response('pong', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** Один прогон (n=1 по умолчанию) в рамках запроса. */
    public function run(Request $request, string $scenario): mixed
    {
        $found = $this->registry->get($scenario);

        if ($found === null) {
            return $this->json(['error' => "Сценарий «{$scenario}» не найден."], 404);
        }

        $n = $this->iterations($request, 1);
        $started = hrtime(true);

        try {
            $r = $this->runner->measure($found, $this->context($request), $n);
        } catch (NoDataException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            error_log("[benchmark] {$scenario}: " . $e::class . ': ' . $e->getMessage());

            return $this->json(['error' => $e::class . ': ' . $e->getMessage()], 500);
        }

        if ($r['errors'] > 0) {
            # Под нагрузкой ответ с 500 виден в wrk только как Non-2xx: причину выводим в лог сервера.
            error_log("[benchmark] {$scenario}: {$r['first_error']}");
        }

        $totalMs = (hrtime(true) - $started) / 1e6;
        $result = $r['_result'];
        unset($r['_result']);

        $body = [
            'scenario' => $r['scenario'],
            'ok' => $r['errors'] === 0,
            'iterations' => $n,
            'ms' => round($totalMs, 3),
            'queries' => (int) round($r['queries_per_op'] * $n),
            'db_ms' => round($r['db_ms_per_op'] * $n, 3),
            'retries' => $r['retries'],
            'pid' => getmypid(),
            'error' => $r['first_error'],
        ];

        if ($request->query('result') !== null) {
            $body['result'] = $result;
        }

        return $this->json($body, $r['errors'] === 0 ? 200 : 500, [
            'Server-Timing' => sprintf('app;dur=%.3f, db;dur=%.3f', $totalMs, $r['db_ms_per_op'] * $n),
        ]);
    }

    /** Пакетный прогон: ?n=50&group=db.read,db.write&only=db.pk,db.list&warmup=3&format=text */
    public function all(Request $request): mixed
    {
        $n = $this->iterations($request, 30);
        $warmup = max(0, min(100, (int) $request->query('warmup', 3)));
        $groups = $this->list($request->query('group'));
        $only = $this->list($request->query('only'));
        $context = $this->context($request);
        $rows = [];

        try {
            foreach ($this->registry->select($groups, $only) as $s) {
                $iterations = $s->heavy ? max(3, intdiv($n, 10)) : $n;
                $r = $this->runner->measure($s, $context, $iterations, $warmup);
                unset($r['_result']);
                $rows[] = $r;
            }
        } catch (NoDataException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        if ($request->query('format') === 'text') {
            return response(Runner::table($rows), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $this->json(['pid' => getmypid(), 'results' => $rows]);
    }

    public function seed(Request $request): mixed
    {
        set_time_limit(0);

        $started = hrtime(true);
        $report = $this->seeder->seed(
            max(0, min(1_000_000, (int) $request->query('users', 1000))),
            max(0, min(5_000_000, (int) $request->query('posts', 10000))),
            max(0, min(10_000_000, (int) $request->query('comments', 50000))),
        );

        return $this->json([
            'seeded' => $report,
            'total_ms' => round((hrtime(true) - $started) / 1e6, 1),
            'counts' => $this->seeder->counts(),
        ]);
    }

    public function reset(): mixed
    {
        return $this->json(['cleared' => $this->seeder->reset()]);
    }

    public function stats(): mixed
    {
        $c = $this->db->connection();
        $driver = $c->driver();
        $version = $c->selectOne($driver === 'sqlite' ? 'SELECT sqlite_version() AS v' : 'SELECT VERSION() AS v')['v'] ?? null;
        $swoole = extension_loaded('swoole') || extension_loaded('openswoole');
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;

        return $this->json([
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'swoole' => $swoole,
            'pid' => getmypid(),
            'memory_mb' => round(memory_get_usage(true) / 1048576, 1),
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'opcache' => is_array($opcache) ? ['enabled' => (bool) ($opcache['opcache_enabled'] ?? false), 'hit_rate' => round((float) ($opcache['opcache_statistics']['opcache_hit_rate'] ?? 0), 2)] : false,
            'db' => ['driver' => $driver, 'version' => $version, 'pool' => $this->db->poolStats()],
            'counts' => $this->seeder->counts(),
        ]);
    }

    /** @param array<string, string> $headers */
    private function json(mixed $data, int $status = 200, array $headers = []): mixed
    {
        return response()->json($data, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function context(Request $request): Context
    {
        $params = array_filter((array) $request->query(), static fn (mixed $v): bool => is_scalar($v));

        return new Context($this->db, $params, (int) $this->config->get('benchmark.bounds_ttl', 5));
    }

    private function iterations(Request $request, int $default): int
    {
        return max(1, min((int) $this->config->get('benchmark.max_iterations', 2000), (int) $request->query('n', $default)));
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return is_string($value) && $value !== '' ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [];
    }
}
