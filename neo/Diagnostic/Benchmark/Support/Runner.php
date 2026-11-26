<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Support;

use Throwable;

/**
 * Прогоняет сценарий n раз внутри процесса и собирает время, число и время SQL-запросов, ошибки и повторы.
 * Это замер БЕЗ HTTP: разницу с wrk даёт стоимость сервера, роутера, middleware и сессии.
 */
final class Runner
{
    /**
     * @return array<string, mixed>
     */
    public function measure(Scenario $scenario, Context $context, int $iterations = 1, int $warmup = 0): array
    {
        $probe = new Probe();

        # Сценарии без БД не должны брать соединение из пула: это съело бы часть замера.
        if ($scenario->group !== 'plain') {
            $context->connection()->listen($probe->listener());
        }

        for ($i = 0; $i < $warmup; $i++) {
            try {
                $scenario->run($context);
            } catch (Throwable) {
                # прогрев ошибки не считает
            }
        }

        $probe->reset();
        $samples = [];
        $errors = 0;
        $retries = 0;
        $firstError = null;
        $result = null;
        $started = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $t = hrtime(true);

            try {
                $result = $scenario->run($context);

                if (is_array($result) && isset($result['retries'])) {
                    $retries += (int) $result['retries'];
                }
            } catch (NoDataException $e) {
                throw $e;
            } catch (Throwable $e) {
                $errors++;
                $firstError ??= $e::class . ': ' . $e->getMessage();
            }

            $samples[] = (hrtime(true) - $t) / 1e6;
        }

        $totalMs = (hrtime(true) - $started) / 1e6;
        $probe->stop();

        return [
            'scenario' => $scenario->name,
            'group' => $scenario->group,
            'iterations' => $iterations,
            'total_ms' => round($totalMs, 3),
            'ops_per_sec' => $totalMs > 0 ? round($iterations / ($totalMs / 1000), 1) : 0.0,
            ...Stats::summarize($samples),
            'queries_per_op' => round($probe->queries / max(1, $iterations), 2),
            'db_ms_per_op' => round($probe->ms / max(1, $iterations), 3),
            'db_share_pct' => $totalMs > 0 ? round($probe->ms / $totalMs * 100, 1) : 0.0,
            'errors' => $errors,
            'retries' => $retries,
            'first_error' => $firstError,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            '_result' => $result,
        ];
    }

    /**
     * Таблица для консоли и ?format=text.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function table(array $rows): string
    {
        $head = sprintf(
            "%-18s %6s %9s %8s %8s %8s %8s %8s %6s %7s %5s %5s",
            'scenario', 'n', 'ops/s', 'avg', 'p50', 'p95', 'p99', 'max', 'q/op', 'db %', 'err', 'retr',
        );
        $lines = [$head, str_repeat('-', strlen($head))];
        $group = null;

        foreach ($rows as $r) {
            if ($r['group'] !== $group) {
                $group = $r['group'];
                $lines[] = "[{$group}]";
            }

            $lines[] = sprintf(
                "%-18s %6d %9.1f %8.3f %8.3f %8.3f %8.3f %8.3f %6.2f %6.1f%% %5d %5d",
                $r['scenario'], $r['iterations'], $r['ops_per_sec'], $r['avg'], $r['p50'], $r['p95'], $r['p99'], $r['max'],
                $r['queries_per_op'], $r['db_share_pct'], $r['errors'], $r['retries'],
            );
        }

        $lines[] = '';
        $lines[] = 'Время в миллисекундах; db % это доля времени SQL в общем времени сценария.';

        return implode("\n", $lines);
    }
}
