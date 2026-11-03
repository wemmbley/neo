<?php

declare(strict_types=1);

namespace Neo\Testing;

/**
 * Запускает тесты: загружает файлы, собирает объявленные `test()` / `it()`,
 * выполняет их и передаёт результаты в {@see Printer}.
 *
 * Все предупреждения и уведомления PHP (включая deprecated) превращаются в исключения,
 * поэтому тест падает на любой сомнительной строчке кода.
 *
 * Запуск из консоли: `php engine/bin/test [путь...] [--filter=текст] [--stop-on-failure]`.
 */
final class Runner
{
    private static ?self $instance = null;

    /** Сколько проверок `expect()` выполнено за весь запуск. */
    private static int $assertions = 0;

    private Scope $scope;

    /** @var list<Test> Тесты текущего файла. */
    private array $tests = [];

    private function __construct()
    {
        $this->scope = new Scope();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Точка входа CLI.
     *
     * @param list<string> $argv       Аргументы командной строки.
     * @param array        $defaultPaths Папка с тестами по умолчанию. Если в ней есть
     *                                 bootstrap.php, он подключается перед запуском.
     *
     * @return int Код выхода: 0 если всё прошло, 1 если есть падения.
     */
    public static function main(array $argv, array $defaultPaths): int
    {
        $paths = [];
        $filter = null;
        $stopOnFailure = false;
        $colors = null;

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--filter=')) {
                $filter = substr($arg, 9);
            } elseif ($arg === '--stop-on-failure') {
                $stopOnFailure = true;
            } elseif ($arg === '--colors' || $arg === '--no-colors') {
                $colors = $arg === '--colors';
            } elseif ($arg === '--help' || $arg === '-h') {
                echo "Usage: php engine/bin/test [path...] [--filter=text] [--stop-on-failure] [--colors|--no-colors]\n";

                return 0;
            } elseif (str_starts_with($arg, '-')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");

                return 2;
            } else {
                $paths[] = $arg;
            }
        }

        $colors ??= stream_isatty(STDOUT) && getenv('NO_COLOR') === false;
        $files = self::discover($paths === [] ? $defaultPaths : $paths);

        if ($files === []) {
            fwrite(STDERR, "No tests found.\n");

            return 1;
        }

        $missing = array_values(array_filter(['pdo_sqlite', 'openssl'], static fn (string $e): bool => !extension_loaded($e)));

        if ($missing !== []) {
            echo "\nВнимание: не включены расширения PHP: " . implode(', ', $missing) . ".\n"
                . "Тесты базы данных и шифрования из-за этого упадут. В php.ini уберите «;» перед extension=...\n"
                . "(консольный php.ini показывает «php --ini»), проверьте командой «php -m».\n";
        }

        $bootstrap = ($defaultPaths[0] ?? '') . '/bootstrap.php';

        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }

        return self::instance()->run($files, $filter, $stopOnFailure, new Printer($colors, (string) getcwd()));
    }

    /**
     * Учитывает выполненную проверку. Вызывается из {@see Expectation}.
     */
    public static function countAssertion(): void
    {
        self::$assertions++;
    }

    // ------------------------------------------------------------------
    // Регистрация (вызывается из Helpers.php)
    // ------------------------------------------------------------------

    public function addTest(string $description, ?\Closure $closure): Test
    {
        return $this->tests[] = new Test($description, $closure, $this->scope);
    }

    public function describe(string $name, \Closure $body): void
    {
        $previous = $this->scope;
        $this->scope = new Scope($name, $previous);

        try {
            $body();
        } finally {
            $this->scope = $previous;
        }
    }

    public function beforeEach(\Closure $hook): void
    {
        $this->scope->before[] = $hook;
    }

    public function afterEach(\Closure $hook): void
    {
        $this->scope->after[] = $hook;
    }

    // ------------------------------------------------------------------
    // Запуск
    // ------------------------------------------------------------------

    /**
     * @param list<string> $files Абсолютные пути к файлам тестов.
     *
     * @return int Код выхода.
     */
    public function run(array $files, ?string $filter, bool $stopOnFailure, Printer $printer): int
    {
        $started = hrtime(true);
        $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
        $failures = [];
        $halt = false;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $printer->start();

        try {
            foreach ($files as $file) {
                $this->scope = new Scope();
                $this->tests = [];
                $results = [];

                try {
                    (static function (string $path): void {
                        require $path;
                    })($file);
                } catch (\Throwable $e) {
                    $results[] = ['name' => 'Could not load the test file', 'status' => 'fail', 'time' => 0.0, 'error' => $e, 'reason' => null, 'file' => $file];
                }

                foreach ($this->tests as $test) {
                    foreach ($test->cases() as $case) {
                        if ($filter !== null && stripos($file . ' ' . $case['name'], $filter) === false) {
                            continue;
                        }

                        $results[] = $result = $this->runCase($test, $case['name'], $case['args'], $file);

                        if ($result['status'] === 'fail' && $stopOnFailure) {
                            $halt = true;
                            break 2;
                        }
                    }
                }

                foreach ($results as $result) {
                    $counts[['pass' => 'passed', 'fail' => 'failed', 'skip' => 'skipped'][$result['status']]]++;

                    if ($result['status'] === 'fail') {
                        $failures[] = $result;
                    }
                }

                $printer->file($file, $results);

                if ($halt) {
                    break;
                }
            }
        } finally {
            restore_error_handler();
        }

        $printer->summary($failures, $counts, self::$assertions, (hrtime(true) - $started) / 1e9);

        return $counts['failed'] > 0 ? 1 : 0;
    }

    /**
     * Выполняет один тест вместе с хуками.
     *
     * @param list<mixed> $args
     *
     * @return array{name: string, status: string, time: float, error: ?\Throwable, reason: ?string, file: string}
     */
    private function runCase(Test $test, string $name, array $args, string $file): array
    {
        $base = ['name' => $name, 'time' => 0.0, 'error' => null, 'reason' => null, 'file' => $file];

        if ($test->skipReason() !== null) {
            return ['status' => 'skip', ...$base, 'reason' => $test->skipReason()];
        }

        if ($test->closure === null) {
            return ['status' => 'skip', ...$base, 'reason' => 'todo'];
        }

        $context = new \stdClass();
        $chain = $test->scope->chain();
        $level = ob_get_level();
        $error = null;
        $started = hrtime(true);

        ob_start();

        try {
            foreach ($chain as $scope) {
                foreach ($scope->before as $hook) {
                    $this->invoke($hook, $context);
                }
            }

            $this->invoke($test->closure, $context, $args);
        } catch (\Throwable $e) {
            $error = $e;
        }

        foreach (array_reverse($chain) as $scope) {
            foreach ($scope->after as $hook) {
                try {
                    $this->invoke($hook, $context);
                } catch (\Throwable $e) {
                    $error ??= $e;
                }
            }
        }

        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        return [
            'status' => $error === null ? 'pass' : 'fail',
            ...$base,
            'time' => (hrtime(true) - $started) / 1e9,
            'error' => $error,
        ];
    }

    /**
     * Вызывает замыкание, привязав `$this` к общему объекту теста.
     *
     * @param list<mixed> $args
     */
    private function invoke(\Closure $closure, \stdClass $context, array $args = []): void
    {
        \Closure::bind($closure, $context)(...$args);
    }

    /**
     * Находит файлы `*Test.php` в указанных папках и файлы, указанные напрямую.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function discover(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $real = realpath($path);

            if ($real === false) {
                throw new \InvalidArgumentException("Путь не найден: {$path}");
            }

            if (is_file($real)) {
                $files[] = $real;
                continue;
            }

            array_push($files, ...self::testFilesIn($real));
        }

        // Убираем дубликаты, сохраняя порядок первого появления.
        return array_values(array_unique($files));
    }

    /**
     * Рекурсивно собирает `*Test.php` в папке.
     *
     * @return list<string>
     */
    private static function testFilesIn(string $directory): array
    {
        $found = [];

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;

            if (is_dir($path)) {
                array_push($found, ...self::testFilesIn($path));
            } elseif (str_ends_with($name, 'Test.php')) {
                $found[] = $path;
            }
        }

        return $found;
    }
}
