<?php

declare(strict_types=1);

use Neo\Autoload\Autoload;

$state = [
    'autoloaders' => [],
    'directory' => null,
];

describe('Autoload', function () use (&$state) {

    beforeEach(function () use (&$state) {
        $state['autoloaders'] = [];

        $state['directory'] = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'neo-autoload-' . bin2hex(random_bytes(6));

        mkdir($state['directory'], 0777, true);
    });

    afterEach(function () use (&$state) {
        foreach ($state['autoloaders'] as $loader) {
            while (spl_autoload_unregister([$loader, 'autoload'])) {
                // Удаляем все регистрации этого экземпляра.
            }
        }

        $removeDirectory = function (string $directory) use (&$removeDirectory): void {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($path) && !is_link($path)) {
                    $removeDirectory($path);
                } else {
                    unlink($path);
                }
            }

            rmdir($directory);
        };

        if (is_dir($state['directory'])) {
            $removeDirectory($state['directory']);
        }
    });

    $createLoader = function (array $classes = [], array $files = []) use (&$state): Autoload {
        $loader = new Autoload($classes, $files);

        $state['autoloaders'][] = $loader;

        return $loader;
    };

    $createClass = function (
        string $basePath,
        string $relativePath,
        string $namespace,
        string $class
    ): string {
        $file = rtrim($basePath, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, $relativePath)
            . '.php';

        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $content = "<?php\n\n"
            . "namespace {$namespace};\n\n"
            . "class {$class}\n"
            . "{\n"
            . "}\n";

        file_put_contents($file, $content);

        return $file;
    };

    describe('Constructor', function () use ($createLoader) {

        it('accepts class mappings', function () use ($createLoader) {
            $loader = $createLoader([
                '/app/src' => 'App',
            ]);

            expect($loader)->toBeInstanceOf(Autoload::class);
        });

        it('accepts file mappings', function () use ($createLoader) {
            $loader = $createLoader([], []);

            expect($loader)->toBeInstanceOf(Autoload::class);
        });

    });

    describe('Class loading', function () use (&$state, $createLoader, $createClass) {

        it('loads a class from its namespace', function () use (&$state, $createLoader, $createClass) {
            $path = $state['directory'] . DIRECTORY_SEPARATOR . 'src';

            $createClass(
                $path,
                'User',
                'AutoloadTest\\App',
                'User'
            );

            $loader = $createLoader([
                $path => 'AutoloadTest\\App',
            ]);

            $loader->load();

            expect(class_exists('AutoloadTest\\App\\User'))->toBeTrue();
        });

        it('loads nested classes from namespace paths', function () use (&$state, $createLoader) {
            $path = $state['directory'] . DIRECTORY_SEPARATOR . 'src';

            $nested = $path . DIRECTORY_SEPARATOR . 'Models';

            mkdir($nested, 0777, true);

            file_put_contents(
                $nested . DIRECTORY_SEPARATOR . 'User.php',
                "<?php\nnamespace AutoloadTest\\Nested\\Models;\nclass User {}"
            );

            $loader = $createLoader([
                $path => 'AutoloadTest\\Nested',
            ]);

            $loader->load();

            expect(class_exists('AutoloadTest\\Nested\\Models\\User'))->toBeTrue();
        });

        it('supports multiple namespace mappings', function () use (&$state, $createLoader) {
            $first = $state['directory'] . DIRECTORY_SEPARATOR . 'first';
            $second = $state['directory'] . DIRECTORY_SEPARATOR . 'second';

            mkdir($first, 0777, true);
            mkdir($second, 0777, true);

            file_put_contents(
                $first . DIRECTORY_SEPARATOR . 'User.php',
                "<?php\nnamespace AutoloadTest\\First;\nclass User {}"
            );

            file_put_contents(
                $second . DIRECTORY_SEPARATOR . 'Post.php',
                "<?php\nnamespace AutoloadTest\\Second;\nclass Post {}"
            );

            $loader = $createLoader([
                $first => 'AutoloadTest\\First',
                $second => 'AutoloadTest\\Second',
            ]);

            $loader->load();

            expect(class_exists('AutoloadTest\\First\\User'))->toBeTrue();
            expect(class_exists('AutoloadTest\\Second\\Post'))->toBeTrue();
        });

        it('supports namespace prefixes without leading separators', function () use (&$state, $createLoader) {
            $path = $state['directory'] . DIRECTORY_SEPARATOR . 'src';

            mkdir($path, 0777, true);

            file_put_contents(
                $path . DIRECTORY_SEPARATOR . 'User.php',
                "<?php\nnamespace AutoloadTest\\NoLeading;\nclass User {}"
            );

            $loader = $createLoader([
                $path => '\\AutoloadTest\\NoLeading\\',
            ]);

            $loader->load();

            expect(class_exists('AutoloadTest\\NoLeading\\User'))->toBeTrue();
        });

        it('does not load classes outside registered namespaces', function () use (&$state, $createLoader) {
            $path = $state['directory'] . DIRECTORY_SEPARATOR . 'src';

            $loader = $createLoader([
                $path => 'AutoloadTest\\Registered',
            ]);

            $loader->load();

            expect(class_exists('UnknownNamespace\\UnknownClass'))->toBeFalse();
        });

        it('does not fail when a class file does not exist', function () use (&$state, $createLoader) {
            $path = $state['directory'] . DIRECTORY_SEPARATOR . 'src';

            $loader = $createLoader([
                $path => 'AutoloadTest\\Missing',
            ]);

            $loader->load();

            expect(class_exists('AutoloadTest\\Missing\\Unknown'))->toBeFalse();
        });

    });

    describe('File loading', function () use (&$state, $createLoader) {

        it('loads existing files', function () use (&$state, $createLoader) {
            $file = $state['directory'] . DIRECTORY_SEPARATOR . 'helpers.php';

            file_put_contents(
                $file,
                '<?php $GLOBALS["neo_autoload_file_loaded"] = true;'
            );

            $loader = $createLoader([], [$file]);

            $loader->load();

            expect($GLOBALS['neo_autoload_file_loaded'])->toBeTrue();

            unset($GLOBALS['neo_autoload_file_loaded']);
        });

        it('skips files that do not exist', function () use (&$state, $createLoader) {
            $file = $state['directory'] . DIRECTORY_SEPARATOR . 'missing.php';

            $loader = $createLoader([], [$file]);

            expect(fn () => $loader->load())->not->toThrow(Throwable::class);
        });

        it('loads multiple files', function () use (&$state, $createLoader) {
            $first = $state['directory'] . DIRECTORY_SEPARATOR . 'first.php';
            $second = $state['directory'] . DIRECTORY_SEPARATOR . 'second.php';

            file_put_contents(
                $first,
                '<?php $GLOBALS["neo_autoload_first"] = true;'
            );

            file_put_contents(
                $second,
                '<?php $GLOBALS["neo_autoload_second"] = true;'
            );

            $loader = $createLoader([], [$first, $second]);

            $loader->load();

            expect($GLOBALS['neo_autoload_first'])->toBeTrue();
            expect($GLOBALS['neo_autoload_second'])->toBeTrue();

            unset(
                $GLOBALS['neo_autoload_first'],
                $GLOBALS['neo_autoload_second']
            );
        });

        it('does not execute the same file more than once', function () use (&$state, $createLoader) {
            $file = $state['directory'] . DIRECTORY_SEPARATOR . 'counter.php';

            file_put_contents(
                $file,
                '<?php $GLOBALS["neo_autoload_counter"] = ($GLOBALS["neo_autoload_counter"] ?? 0) + 1;'
            );

            $loader = $createLoader([], [$file, $file]);

            $loader->load();

            expect($GLOBALS['neo_autoload_counter'])->toBe(1);

            unset($GLOBALS['neo_autoload_counter']);
        });

        it('does not register the same callback more than once', function () use ($createLoader) {
            $loader = $createLoader();

            $loader->load();

            $firstCount = count(spl_autoload_functions());

            $loader->load();

            $secondCount = count(spl_autoload_functions());

            expect($secondCount)->toBe($firstCount);
        });

    });

    describe('SPL registration', function () use (&$state, $createLoader) {

        it('registers the autoload callback', function () use (&$state, $createLoader) {
            $loader = $createLoader();

            $loader->load();

            expect(
                spl_autoload_functions()
            )->toContain([$loader, 'autoload']);
        });

        it('registers the callback only once per loader instance', function () use ($createLoader) {
            $loader = $createLoader();

            $loader->load();
            $loader->load();

            $registrations = array_filter(
                spl_autoload_functions(),
                fn ($callback) => $callback === [$loader, 'autoload']
            );

            expect($registrations)->toHaveCount(1);
        });

    });

});
