<?php

declare(strict_types=1);

use Neo\Logger\Drivers\DailyReportDriver;
use Neo\Logger\Drivers\DisableReportingReportDriver;
use Neo\Logger\Drivers\MonthlyReportDriver;
use Neo\Logger\Drivers\SingleReportDriver;
use Neo\Logger\Drivers\StackReportDriver;

require_once 'TestHelpers.php';

// ---------------------------------------------------------------------
// Формат и запись
// ---------------------------------------------------------------------

it('writes a formatted line with level, message and json context', function () {
    $path = logger_tmp_dir() . '/app.log';

    (new SingleReportDriver(['path' => $path]))->error('Boom', ['id' => 5]);

    $line = rtrim(logger_read($path));

    expect(preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] error: Boom \{"id":5\}$/', $line))->toBe(1);
});

it('does not leave trailing spaces when context is empty', function () {
    $path = logger_tmp_dir() . '/app.log';

    (new SingleReportDriver(['path' => $path]))->info('Hello');

    expect(logger_read($path))->toContain('info: Hello' . PHP_EOL);
    expect(str_contains(logger_read($path), 'Hello ' . PHP_EOL))->toBeFalse();
});

it('creates missing directories', function () {
    $path = logger_tmp_dir() . '/a/b/c/app.log';

    (new SingleReportDriver(['path' => $path]))->info('x');

    expect(is_file($path))->toBeTrue();
});

it('appends instead of overwriting', function () {
    $path = logger_tmp_dir() . '/app.log';
    $driver = new SingleReportDriver(['path' => $path]);

    $driver->info('first');
    $driver->info('second');

    expect(substr_count(logger_read($path), PHP_EOL))->toBe(2);
});

it('keeps unicode and slashes readable', function () {
    $path = logger_tmp_dir() . '/app.log';

    (new SingleReportDriver(['path' => $path]))->info('x', ['имя' => 'a/b']);

    expect(logger_read($path))->toContain('{"имя":"a/b"}');
});

// ---------------------------------------------------------------------
// Плейсхолдеры и контекст
// ---------------------------------------------------------------------

it('interpolates scalar placeholders', function () {
    $path = logger_tmp_dir() . '/app.log';

    (new SingleReportDriver(['path' => $path]))->info('User {id} logged in as {name}', ['id' => 5, 'name' => 'Ann']);

    expect(logger_read($path))->toContain('User 5 logged in as Ann');
});

it('leaves unknown and non-scalar placeholders untouched', function () {
    $path = logger_tmp_dir() . '/app.log';

    (new SingleReportDriver(['path' => $path]))->info('A {missing} B {list}', ['list' => [1, 2]]);

    expect(logger_read($path))->toContain('A {missing} B {list}');
});

it('serializes exceptions in context instead of printing {}', function () {
    $path = logger_tmp_dir() . '/app.log';

    (new SingleReportDriver(['path' => $path]))->error('fail', ['exception' => new RuntimeException('kaboom', 7)]);

    $content = logger_read($path);

    expect($content)->toContain('RuntimeException');
    expect($content)->toContain('kaboom');
    expect($content)->toContain('"code":7');
    expect(str_contains($content, '"exception":{}'))->toBeFalse();
});

it('does not lose the line when context contains invalid utf-8', function () {
    $path = logger_tmp_dir() . '/app.log';

    (new SingleReportDriver(['path' => $path]))->warning('still here', ['bad' => "\xB1\x31"]);

    expect(logger_read($path))->toContain('warning: still here');
});

it('describes objects and resources safely', function () {
    $path = logger_tmp_dir() . '/app.log';
    $handle = fopen('php://memory', 'r');

    (new SingleReportDriver(['path' => $path]))->info('x', ['obj' => new stdClass(), 'res' => $handle]);

    fclose($handle);

    expect(logger_read($path))->toContain('stdClass');
    expect(logger_read($path))->toContain('resource(stream)');
});

// ---------------------------------------------------------------------
// Уровни
// ---------------------------------------------------------------------

it('filters records below the minimum level', function () {
    $path = logger_tmp_dir() . '/app.log';
    $driver = new SingleReportDriver(['path' => $path, 'level' => 'warning']);

    $driver->debug('d');
    $driver->info('i');
    $driver->notice('n');

    expect(is_file($path))->toBeFalse();

    $driver->warning('w');
    $driver->error('e');
    $driver->emergency('m');

    expect(substr_count(logger_read($path), PHP_EOL))->toBe(3);
});

it('writes every level when no minimum is configured', function () {
    $path = logger_tmp_dir() . '/app.log';
    $driver = new SingleReportDriver(['path' => $path]);

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
        $driver->{$level}("msg-{$level}");
    }

    expect(substr_count(logger_read($path), PHP_EOL))->toBe(8);
});

it('rejects an unknown level in log()', function () {
    $driver = new SingleReportDriver(['path' => logger_tmp_dir() . '/app.log']);

    expect(fn () => $driver->log('loud', 'x'))->toThrow(InvalidArgumentException::class);
});

it('rejects an invalid minimum level at construction', function () {
    expect(fn () => new SingleReportDriver(['path' => logger_tmp_dir() . '/app.log', 'level' => 'loud']))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------
// Файловые драйверы
// ---------------------------------------------------------------------

it('requires a path for file drivers', function () {
    foreach ([SingleReportDriver::class, DailyReportDriver::class, MonthlyReportDriver::class] as $class) {
        expect(fn () => new $class([]))->toThrow(InvalidArgumentException::class);
        expect(fn () => new $class(['path' => '']))->toThrow(InvalidArgumentException::class);
    }
});

it('daily driver inserts the date before the extension', function () {
    $dir = logger_tmp_dir();

    (new DailyReportDriver(['path' => $dir . '/app.log']))->info('x');

    expect(is_file($dir . '/app-' . date('Y-m-d') . '.log'))->toBeTrue();
    expect(is_file($dir . '/app.log'))->toBeFalse();
});

it('monthly driver inserts year and month before the extension', function () {
    $dir = logger_tmp_dir();

    (new MonthlyReportDriver(['path' => $dir . '/app.log']))->info('x');

    expect(is_file($dir . '/app-' . date('Y-m') . '.log'))->toBeTrue();
});

it('suffixes files without an extension', function () {
    $dir = logger_tmp_dir();

    (new DailyReportDriver(['path' => $dir . '/app']))->info('x');

    expect(is_file($dir . '/app-' . date('Y-m-d')))->toBeTrue();
});

it('throws RuntimeException when the file cannot be written', function () {
    $dir = logger_tmp_dir();
    file_put_contents($dir . '/blocker', 'x');

    // «Каталог» логов лежит внутри обычного файла: создать его нельзя.
    $driver = new SingleReportDriver(['path' => $dir . '/blocker/app.log']);

    expect(fn () => @$driver->info('x'))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------
// Disabled и Stack
// ---------------------------------------------------------------------

it('disabled driver writes nothing', function () {
    $driver = new DisableReportingReportDriver();

    $driver->error('x');

    expect(true)->toBeTrue();
});

it('disabled driver still validates levels', function () {
    expect(fn () => (new DisableReportingReportDriver())->log('loud', 'x'))
        ->toThrow(InvalidArgumentException::class);
});

it('stack forwards records to every driver', function () {
    $a = logger_spy();
    $b = logger_spy();

    (new StackReportDriver([], [$a, $b]))->warning('hey', ['k' => 1]);

    expect($a->records)->toHaveCount(1);
    expect($b->records[0]['message'])->toBe('hey');
    expect($b->records[0]['context'])->toBe(['k' => 1]);
});

it('stack applies its own minimum level', function () {
    $a = logger_spy();

    (new StackReportDriver(['level' => 'error'], [$a]))->info('quiet');

    expect($a->records)->toHaveCount(0);
});

it('stack keeps going when one driver fails and rethrows afterwards', function () {
    $bad = logger_spy(true);
    $good = logger_spy();
    $stack = new StackReportDriver([], [$bad, $good]);

    expect(fn () => $stack->error('x'))->toThrow(RuntimeException::class);
    expect($good->records)->toHaveCount(1);
});