<?php

declare(strict_types=1);

namespace Neo\Testing;

/**
 * Красивый вывод результатов в консоль в духе Pest: цветные плашки PASS/FAIL,
 * галочки, время, а для упавших тестов ожидаемое/полученное и кусок кода с местом падения.
 *
 * Цвета отключаются сами, если вывод не в терминале (или задан NO_COLOR).
 *
 * @phpstan-type Result array{name: string, status: string, time: float, error: ?\Throwable, reason: ?string, file: string}
 */
final class Printer
{
    private const BADGES = [
        'pass' => '1;97;42',
        'fail' => '1;97;41',
    ];

    public function __construct(
        private readonly bool $colors,
        private readonly string $cwd = '',
    ) {
    }

    /** Вывод перед первым файлом. */
    public function start(): void
    {
        echo "\n";
    }

    /**
     * Результаты одного файла: плашка и строки тестов.
     *
     * @param array<int, array<string, mixed>> $results См. тип Result.
     */
    public function file(string $file, array $results): void
    {
        if ($results === []) {
            return;
        }

        $failed = array_filter($results, static fn (array $r): bool => $r['status'] === 'fail') !== [];
        $badge = $failed ? 'fail' : 'pass';

        echo '  ' . $this->paint(' ' . strtoupper($badge) . ' ', self::BADGES[$badge]) . '  ' . $this->relative($file) . "\n";

        foreach ($results as $result) {
            echo $this->line($result);
        }

        echo "\n";
    }

    /**
     * Подробности падений и итоговая строка.
     *
     * @param array<int, array<string, mixed>> $failures
     * @param array{passed: int, failed: int, skipped: int} $counts
     */
    public function summary(array $failures, array $counts, int $assertions, float $duration): void
    {
        foreach ($failures as $failure) {
            $this->failure($failure);
        }

        $parts = [];

        if ($counts['failed'] > 0) {
            $parts[] = $this->paint($counts['failed'] . ' failed', '1;31');
        }

        if ($counts['skipped'] > 0) {
            $parts[] = $this->paint($counts['skipped'] . ' skipped', '1;33');
        }

        if ($counts['passed'] > 0) {
            $parts[] = $this->paint($counts['passed'] . ' passed', '1;32');
        }

        echo '  ' . $this->paint('Tests:   ', '2') . ' ' . implode(', ', $parts) . $this->paint(" ({$assertions} assertions)", '2') . "\n";
        echo '  ' . $this->paint('Duration:', '2') . ' ' . sprintf('%.2fs', $duration) . "\n\n";
    }

    // ------------------------------------------------------------------

    /**
     * Строка одного теста: значок, название, точки и время.
     *
     * @param array<string, mixed> $result
     */
    private function line(array $result): string
    {
        $name = $result['name'];

        if ($result['status'] === 'skip') {
            $reason = $result['reason'] !== null && $result['reason'] !== '' ? ' (' . $result['reason'] . ')' : '';

            return '  ' . $this->paint('-', '33') . ' ' . $this->paint($name . $reason, '2') . "\n";
        }

        $time = $result['time'] >= 1 ? sprintf('%.2fs', $result['time']) : sprintf('%d ms', (int) round($result['time'] * 1000));
        $icon = $result['status'] === 'pass' ? $this->paint('✓', '32') : $this->paint('⨯', '31');
        $label = $result['status'] === 'pass' ? $name : $this->paint($name, '31');
        $width = max(60, min(100, (int) (getenv('COLUMNS') ?: 80)));
        $dots = max(2, $width - $this->length($name) - $this->length($time) - 8);

        return "  {$icon} {$label} " . $this->paint(str_repeat('.', $dots) . ' ' . $time, '2') . "\n";
    }

    /**
     * Подробное описание одного падения.
     *
     * @param array<string, mixed> $failure
     */
    private function failure(array $failure): void
    {
        /** @var \Throwable $error */
        $error = $failure['error'];

        echo '  ' . $this->paint(str_repeat('─', 72), '2') . "\n";
        echo '  ' . $this->paint(' FAILED ', self::BADGES['fail']) . '  ' . $this->relative($failure['file']) . ' › ' . $failure['name'] . "\n\n";

        if ($error instanceof AssertionFailed) {
            echo '  ' . $this->paint($error->getMessage(), '1') . "\n";

            if ($error->expected !== null) {
                echo '  ' . $this->paint('Expected:', '32') . ' ' . $error->expected . "\n";
                echo '  ' . $this->paint('Actual:  ', '31') . ' ' . $error->actual . "\n";
            }
        } else {
            echo '  ' . $this->paint($error::class, '1;31') . ': ' . $error->getMessage() . "\n";
        }

        $locations = $this->locations($error, $failure['file']);

        foreach ($locations as [$file, $line]) {
            echo "\n  " . $this->paint('at', '2') . ' ' . $this->relative($file) . ':' . $line . "\n";
        }

        [$file, $line] = end($locations);
        echo $this->excerpt($file, $line) . "\n";
    }

    /**
     * Где показать код: место падения и, если оно не в тесте, ближайший вызов из теста.
     *
     * @return non-empty-list<array{0: string, 1: int}>
     */
    private function locations(\Throwable $error, string $testFile): array
    {
        if ($error instanceof AssertionFailed) {
            return [[$error->testFile, $error->testLine]];
        }

        $locations = [[$error->getFile(), $error->getLine()]];

        if (realpath($error->getFile()) !== $testFile) {
            foreach ($error->getTrace() as $frame) {
                if (isset($frame['file'], $frame['line']) && realpath($frame['file']) === $testFile) {
                    $locations[] = [$frame['file'], $frame['line']];
                    break;
                }
            }
        }

        return $locations;
    }

    /**
     * Фрагмент исходника вокруг строки с пометкой «➜».
     */
    private function excerpt(string $file, int $line): string
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;

        if ($lines === false || $line < 1) {
            return '';
        }

        $out = '';

        for ($n = max(1, $line - 2); $n <= min(count($lines), $line + 2); $n++) {
            $code = $lines[$n - 1];
            $gutter = sprintf('%4d │ ', $n);

            $out .= $n === $line
                ? '  ' . $this->paint('➜', '31') . $this->paint($gutter, '31') . $code . "\n"
                : '   ' . $this->paint($gutter, '2') . $this->paint($code, '2') . "\n";
        }

        return $out;
    }

    /**
     * Длина строки в символах (без зависимости от mbstring).
     */
    private function length(string $text): int
    {
        return (int) preg_match_all('/./su', $text);
    }

    private function relative(string $path): string
    {
        $prefix = rtrim($this->cwd, '/') . '/';

        return $this->cwd !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function paint(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
