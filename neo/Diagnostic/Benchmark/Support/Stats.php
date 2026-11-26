<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Support;

/** Перцентили и сводка по набору замеров (мс). */
final class Stats
{
    /**
     * @param list<float> $samples миллисекунды
     * @return array{min: float, avg: float, p50: float, p95: float, p99: float, max: float}
     */
    public static function summarize(array $samples): array
    {
        if ($samples === []) {
            return ['min' => 0.0, 'avg' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'max' => 0.0];
        }

        sort($samples);

        return [
            'min' => round($samples[0], 3),
            'avg' => round(array_sum($samples) / count($samples), 3),
            'p50' => round(self::percentile($samples, 50), 3),
            'p95' => round(self::percentile($samples, 95), 3),
            'p99' => round(self::percentile($samples, 99), 3),
            'max' => round($samples[count($samples) - 1], 3),
        ];
    }

    /** @param list<float> $sorted */
    public static function percentile(array $sorted, float $p): float
    {
        $rank = (int) ceil(($p / 100) * count($sorted)) - 1;

        return $sorted[max(0, min(count($sorted) - 1, $rank))];
    }
}
