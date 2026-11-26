<?php

declare(strict_types=1);

namespace Neo\Foundation\Console\Commands;

use Neo\Foundation\Console\Commands\Command;
use RuntimeException;

class CapacityCommand extends Command
{
    protected string $signature = 'capacity
        {url? : Target HTTP URL}
        {--duration=10 : Duration of each test stage in seconds}
        {--levels=10,25,50,100,200,400 : Comma-separated concurrency levels}
        {--timeout=10 : HTTP request timeout in seconds}
        {--max-p95=250 : Maximum acceptable P95 latency in milliseconds}';

    protected string $description = 'Analyze server capacity and recommend CPU and RAM resources';

    private array $cpuSamples = [];
    private float $memoryPeak = 0;

    public function handle(): int
    {
        if (!extension_loaded('curl')) {
            $this->error('The cURL extension is required.');
            return 1;
        }

        if (!is_file('/proc/stat') || !is_file('/proc/meminfo')) {
            $this->error('Linux /proc filesystem is required.');
            return 1;
        }

        $url = $this->argument('url') ?: 'http://127.0.0.1:8000/';

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Invalid target URL.');
            return 1;
        }

        $duration = max(3, (int) $this->option('duration'));
        $timeout = max(1, (int) $this->option('timeout'));
        $maxP95 = max(1, (float) $this->option('max-p95'));

        $levels = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $this->option('levels'))),
            static fn (int $value) => $value > 0
        )));

        sort($levels);

        if (!$levels) {
            $this->error('Invalid concurrency levels.');
            return 1;
        }

        $this->info('Neo Capacity Analyzer');
        $this->line(str_repeat('─', 55));

        $this->table(
            ['Resource', 'Value'],
            [
                ['Target', $url],
                ['CPU cores', $this->cpuCount()],
                ['Total RAM', $this->formatBytes($this->memory()['total'])],
                ['Duration', "{$duration} seconds per stage"],
                ['Concurrency levels', implode(', ', $levels)],
            ]
        );

        $results = [];
        $lastStable = null;

        foreach ($levels as $concurrency) {
            $this->newLine();

            $this->info("Testing {$concurrency} concurrent connections...");

            try {
                $result = $this->runStage(
                    $url,
                    $concurrency,
                    $duration,
                    $timeout
                );
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return 1;
            }

            $result['stable'] =
                $result['errors_percent'] <= 0.1
                && $result['p95'] <= $maxP95
                && $result['cpu_avg'] <= 85
                && $result['memory_max'] <= 85;

            $results[] = $result;

            $this->table(
                ['Metric', 'Result'],
                [
                    ['Requests/sec', round($result['rps'], 2)],
                    ['Requests completed', $result['requests']],
                    ['Average latency', round($result['avg'], 2) . ' ms'],
                    ['P50', round($result['p50'], 2) . ' ms'],
                    ['P95', round($result['p95'], 2) . ' ms'],
                    ['P99', round($result['p99'], 2) . ' ms'],
                    ['CPU average', round($result['cpu_avg'], 1) . '%'],
                    ['CPU peak', round($result['cpu_max'], 1) . '%'],
                    ['RAM peak', round($result['memory_max'], 1) . '%'],
                    ['Errors', $result['errors']],
                    ['Error rate', round($result['errors_percent'], 3) . '%'],
                    ['Status', $result['stable'] ? 'STABLE' : 'SATURATED'],
                ]
            );

            if (!$result['stable']) {
                $this->warn('Capacity threshold reached.');
                break;
            }

            $lastStable = $result;

            sleep(2);
        }

        $this->report($results, $lastStable);

        return $lastStable !== null ? 0 : 1;
    }

    private function runStage(
        string $url,
        int $concurrency,
        int $duration,
        int $timeout
    ): array {
        $multi = curl_multi_init();

        if ($multi === false) {
            throw new RuntimeException('Unable to initialize cURL multi.');
        }

        $active = [];
        $latencies = [];

        $completed = 0;
        $errors = 0;

        $startedAt = microtime(true);
        $deadline = $startedAt + $duration;

        $nextSample = $startedAt;
        $previousCpu = $this->readCpu();

        $cpuSamples = [];
        $memoryMax = 0;

        try {
            while (microtime(true) < $deadline || $active !== []) {

                while (
                    microtime(true) < $deadline
                    && count($active) < $concurrency
                ) {
                    $handle = curl_init($url);

                    curl_setopt_array($handle, [
                        CURLOPT_RETURNTRANSFER => false,
                        CURLOPT_WRITEFUNCTION => static fn ($ch, string $data): int => strlen($data),
                        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                        CURLOPT_TIMEOUT => $timeout,
                        CURLOPT_NOSIGNAL => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HTTPGET => true,
                        CURLOPT_HTTPHEADER => [
                            'Connection: keep-alive',
                        ],
                    ]);

                    $id = spl_object_id($handle);

                    $active[$id] = [
                        'handle' => $handle,
                        'started' => hrtime(true),
                    ];

                    curl_multi_add_handle($multi, $handle);
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK) {
                    throw new RuntimeException(
                        'cURL multi error: ' . curl_multi_strerror($status)
                    );
                }

                while ($info = curl_multi_info_read($multi)) {
                    $handle = $info['handle'];
                    $id = spl_object_id($handle);

                    if (!isset($active[$id])) {
                        continue;
                    }

                    $request = $active[$id];

                    $latencies[] = (
                            hrtime(true) - $request['started']
                        ) / 1_000_000;

                    $httpCode = (int) curl_getinfo(
                        $handle,
                        CURLINFO_RESPONSE_CODE
                    );

                    if (
                        $info['result'] !== CURLE_OK
                        || $httpCode < 200
                        || $httpCode >= 400
                    ) {
                        $errors++;
                    }

                    $completed++;

                    curl_multi_remove_handle($multi, $handle);

                    unset($active[$id]);
                }

                if (microtime(true) >= $nextSample) {
                    $currentCpu = $this->readCpu();

                    $cpu = $this->cpuUsage($previousCpu, $currentCpu);

                    if ($cpu !== null) {
                        $cpuSamples[] = $cpu;
                    }

                    $previousCpu = $currentCpu;

                    $memoryMax = max(
                        $memoryMax,
                        $this->memory()['used_percent']
                    );

                    $nextSample = microtime(true) + 1;
                }

                if ($running > 0) {
                    if (curl_multi_select($multi, 0.1) === -1) {
                        usleep(1000);
                    }
                } elseif (microtime(true) < $deadline) {
                    usleep(1000);
                }
            }
        } finally {
            foreach ($active as $request) {
                curl_multi_remove_handle(
                    $multi,
                    $request['handle']
                );
            }

            curl_multi_close($multi);
        }

        $elapsed = microtime(true) - $startedAt;

        sort($latencies);

        $count = count($latencies);

        return [
            'concurrency' => $concurrency,
            'requests' => $completed,
            'rps' => $elapsed > 0 ? $completed / $elapsed : 0,
            'avg' => $count > 0 ? array_sum($latencies) / $count : 0,
            'p50' => $this->percentile($latencies, 50),
            'p95' => $this->percentile($latencies, 95),
            'p99' => $this->percentile($latencies, 99),
            'errors' => $errors,
            'errors_percent' => $completed > 0
                ? ($errors / $completed) * 100
                : 100,
            'cpu_avg' => $cpuSamples
                ? array_sum($cpuSamples) / count($cpuSamples)
                : 0,
            'cpu_max' => $cpuSamples ? max($cpuSamples) : 0,
            'memory_max' => $memoryMax,
        ];
    }

    private function percentile(array $values, int $percentile): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0;
        }

        $index = (int) ceil($percentile / 100 * $count) - 1;

        return $values[max(0, min($count - 1, $index))];
    }

    private function readCpu(): array
    {
        $line = strtok(file_get_contents('/proc/stat'), "\n");
        $values = preg_split('/\s+/', trim($line));

        $values = array_map('intval', array_slice($values, 1));

        return [
            'total' => array_sum($values),
            'idle' => ($values[3] ?? 0) + ($values[4] ?? 0),
        ];
    }

    private function cpuUsage(array $before, array $after): ?float
    {
        $total = $after['total'] - $before['total'];
        $idle = $after['idle'] - $before['idle'];

        if ($total <= 0) {
            return null;
        }

        return max(0, min(100, (1 - $idle / $total) * 100));
    }

    private function memory(): array
    {
        $data = file_get_contents('/proc/meminfo');

        preg_match('/^MemTotal:\s+(\d+)/m', $data, $total);
        preg_match('/^MemAvailable:\s+(\d+)/m', $data, $available);

        $total = (int) ($total[1] ?? 0) * 1024;
        $available = (int) ($available[1] ?? 0) * 1024;

        return [
            'total' => $total,
            'available' => $available,
            'used_percent' => $total > 0
                ? (($total - $available) / $total) * 100
                : 0,
        ];
    }

    private function cpuCount(): int
    {
        $stat = file('/proc/stat', FILE_IGNORE_NEW_LINES);

        return count(array_filter(
            $stat,
            static fn (string $line): bool =>
                preg_match('/^cpu\d+\s/', $line) === 1
        ));
    }

    private function report(array $results, ?array $best): void
    {
        $this->newLine();
        $this->info('CAPACITY REPORT');
        $this->line(str_repeat('─', 55));

        if ($best === null) {
            $this->error('No stable capacity level detected.');
            return;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Maximum stable RPS', round($best['rps'])],
                ['Stable concurrency', $best['concurrency']],
                ['P95 latency', round($best['p95'], 2) . ' ms'],
                ['P99 latency', round($best['p99'], 2) . ' ms'],
                ['CPU average', round($best['cpu_avg'], 1) . '%'],
                ['RAM peak', round($best['memory_max'], 1) . '%'],
            ]
        );

        $cores = $this->cpuCount();
        $memory = $this->memory();

        $this->newLine();
        $this->info('RESOURCE RECOMMENDATIONS');

        if ($best['cpu_avg'] >= 70) {
            $recommended = (int) ceil(
                $cores * ($best['cpu_avg'] / 70)
            );

            $this->warn("Consider {$recommended} vCPU or more.");
        } else {
            $this->info('CPU capacity appears sufficient.');
        }

        if ($best['memory_max'] >= 80) {
            $recommendedRam = (int) ceil(
                $memory['total'] * ($best['memory_max'] / 75)
            );

            $this->warn(
                'Consider at least ' . $this->formatBytes($recommendedRam) . ' RAM.'
            );
        } else {
            $this->info('RAM capacity appears sufficient.');
        }

        if ($best['cpu_avg'] < 40 && $best['memory_max'] < 50) {
            $this->comment(
                'A smaller VPS may be sufficient. Verify with a separate test on reduced resources.'
            );
        }

        $this->newLine();
        $this->comment(
            'For accurate measurements, run the load generator on a separate machine.'
        );
        $this->comment(
            'CPU and RAM values represent the entire host, not Neo workers alone.'
        );
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, 2) . ' ' . $units[$unit];
    }
}
