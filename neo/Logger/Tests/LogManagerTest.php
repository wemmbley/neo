<?php

declare(strict_types=1);

use Neo\Logger\Channels\FileReportChannel;
use Neo\Logger\Channels\ReportChannelContract;
use Neo\Logger\Channels\TelegramReportChannel;
use Neo\Logger\Drivers\Basic\ReportDriverContract;
use Neo\Logger\Drivers\DailyReportDriver;
use Neo\Logger\Drivers\DisableReportingReportDriver;
use Neo\Logger\Drivers\SingleReportDriver;
use Neo\Logger\Drivers\StackReportDriver;
use Neo\Logger\Managers\LogManager;

require_once 'TestHelpers.php';

/** Канал-шпион: запоминает исключения или падает. */
function logger_channel_spy(bool $fails = false): ReportChannelContract
{
    return new class($fails) implements ReportChannelContract {
        /** @var Throwable[] */
        public array $sent = [];

        public function __construct(private bool $fails) {}

        public function send(Throwable $exception): void
        {
            if ($this->fails) {
                throw new RuntimeException('channel failed');
            }

            $this->sent[] = $exception;
        }
    };
}

// ---------------------------------------------------------------------
// Делегирование драйверу по умолчанию
// ---------------------------------------------------------------------

it('implements the driver contract', function () {
    expect(logger_manager(logger_tmp_dir()))->toBeInstanceOf(ReportDriverContract::class);
});

it('writes to the default driver', function () {
    $dir = logger_tmp_dir();

    logger_manager($dir)->error('Boom', ['id' => 1]);

    expect(logger_read($dir . '/app.log'))->toContain('error: Boom {"id":1}');
});

it('uses the configured default driver', function () {
    $dir = logger_tmp_dir();

    logger_manager($dir, ['default' => 'daily'])->info('x');

    expect(is_file($dir . '/daily-' . date('Y-m-d') . '.log'))->toBeTrue();
    expect(is_file($dir . '/app.log'))->toBeFalse();
});

it('exposes all eight level methods and log()', function () {
    $dir = logger_tmp_dir();
    $log = logger_manager($dir);

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
        $log->{$level}("via-{$level}");
        expect(logger_read($dir . '/app.log'))->toContain("{$level}: via-{$level}");
    }

    $log->log('notice', 'via-log');

    expect(logger_read($dir . '/app.log'))->toContain('notice: via-log');
});

// ---------------------------------------------------------------------
// driver()
// ---------------------------------------------------------------------

it('resolves drivers by name with the right class', function () {
    $log = logger_manager(logger_tmp_dir());

    expect($log->driver('single'))->toBeInstanceOf(SingleReportDriver::class);
    expect($log->driver('daily'))->toBeInstanceOf(DailyReportDriver::class);
    expect($log->driver('both'))->toBeInstanceOf(StackReportDriver::class);
    expect($log->driver('off'))->toBeInstanceOf(DisableReportingReportDriver::class);
});

it('caches resolved drivers', function () {
    $log = logger_manager(logger_tmp_dir());

    expect($log->driver('single') === $log->driver('single'))->toBeTrue();
    expect($log->driver() === $log->driver('single'))->toBeTrue();
    expect($log->driver('single') === $log->driver('daily'))->toBeFalse();
});

it('throws for an unknown driver name', function () {
    $log = logger_manager(logger_tmp_dir());

    expect(fn () => $log->driver('nope'))->toThrow(InvalidArgumentException::class);
});

it('throws when a driver config has no driver key', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => ['broken' => ['path' => '/tmp/x.log']],
        'default' => 'broken',
    ]);

    expect(fn () => $log->driver())->toThrow(InvalidArgumentException::class);
});

it('throws for an unsupported driver type', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => ['weird' => ['driver' => 'carrier-pigeon']],
        'default' => 'weird',
    ]);

    expect(fn () => $log->driver())->toThrow(InvalidArgumentException::class);
});

it('throws when a file driver has no path', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => ['nopath' => ['driver' => 'single']],
        'default' => 'nopath',
    ]);

    expect(fn () => $log->driver())->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------
// stack
// ---------------------------------------------------------------------

it('writes to every driver of a configured stack', function () {
    $dir = logger_tmp_dir();

    logger_manager($dir, ['default' => 'both'])->warning('twice');

    expect(logger_read($dir . '/app.log'))->toContain('warning: twice');
    expect(logger_read($dir . '/daily-' . date('Y-m-d') . '.log'))->toContain('warning: twice');
});

it('rejects an empty stack', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => ['empty' => ['driver' => 'stack', 'drivers' => []]],
        'default' => 'empty',
    ]);

    expect(fn () => $log->driver())->toThrow(InvalidArgumentException::class);
});

it('rejects a stack that references itself', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => ['loop' => ['driver' => 'stack', 'drivers' => ['loop']]],
        'default' => 'loop',
    ]);

    expect(fn () => $log->driver())->toThrow(InvalidArgumentException::class);
});

it('rejects an indirect stack cycle', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => [
            'a' => ['driver' => 'stack', 'drivers' => ['b']],
            'b' => ['driver' => 'stack', 'drivers' => ['a']],
        ],
        'default' => 'a',
    ]);

    expect(fn () => $log->driver())->toThrow(InvalidArgumentException::class);
});

it('rejects a stack that references an unknown driver', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => ['s' => ['driver' => 'stack', 'drivers' => ['ghost']]],
        'default' => 's',
    ]);

    expect(fn () => $log->driver())->toThrow(InvalidArgumentException::class);
});

it('builds an ad hoc stack from configured drivers', function () {
    $dir = logger_tmp_dir();
    $log = logger_manager($dir);

    $log->stack(['single', 'monthly'])->error('adhoc');

    expect(logger_read($dir . '/app.log'))->toContain('error: adhoc');
    expect(logger_read($dir . '/monthly-' . date('Y-m') . '.log'))->toContain('error: adhoc');
});

it('ad hoc stack accepts extra config such as level', function () {
    $dir = logger_tmp_dir();

    logger_manager($dir)->stack(['single'], ['level' => 'error'])->info('quiet');

    expect(is_file($dir . '/app.log'))->toBeFalse();
});

// ---------------------------------------------------------------------
// build / extend
// ---------------------------------------------------------------------

it('builds a driver on the fly without registering it', function () {
    $dir = logger_tmp_dir();
    $log = logger_manager($dir);

    $driver = $log->build(['driver' => 'single', 'path' => $dir . '/custom.log']);
    $driver->info('on the fly');

    expect(logger_read($dir . '/custom.log'))->toContain('on the fly');
    expect(fn () => $log->driver('custom'))->toThrow(InvalidArgumentException::class);
});

it('supports custom driver types through extend()', function () {
    $spy = logger_spy();
    $received = null;

    $log = logger_manager(logger_tmp_dir(), [
        'drivers' => ['mine' => ['driver' => 'spy', 'flag' => true]],
        'default' => 'mine',
    ]);

    $result = $log->extend('spy', function (array $config, LogManager $manager) use ($spy, &$received) {
        $received = [$config, $manager];

        return $spy;
    });

    $log->info('custom');

    expect($result === $log)->toBeTrue();
    expect($received[0]['flag'])->toBeTrue();
    expect($received[1] === $log)->toBeTrue();
    expect($spy->records[0]['message'])->toBe('custom');
});

it('disabled driver from config writes nothing', function () {
    $dir = logger_tmp_dir();

    logger_manager($dir, ['default' => 'off'])->emergency('silence');

    expect(is_file($dir . '/app.log'))->toBeFalse();
});

// ---------------------------------------------------------------------
// Каналы
// ---------------------------------------------------------------------

it('resolves the file channel and logs exceptions', function () {
    $dir = logger_tmp_dir();
    $log = logger_manager($dir);

    expect($log->channel('file'))->toBeInstanceOf(FileReportChannel::class);

    $log->channel('file')->send(new RuntimeException('channel boom'));

    $content = logger_read($dir . '/app.log');

    expect($content)->toContain('error: channel boom');
    expect($content)->toContain('RuntimeException');
});

it('file channel honours the level option', function () {
    $dir = logger_tmp_dir();
    $log = logger_manager($dir, [
        'channels' => ['file' => ['channel' => 'file', 'driver' => 'single', 'level' => 'critical']],
    ]);

    $log->channel('file')->send(new LogicException('serious'));

    expect(logger_read($dir . '/app.log'))->toContain('critical: serious');
});

it('caches channels', function () {
    $log = logger_manager(logger_tmp_dir());

    expect($log->channel('file') === $log->channel('file'))->toBeTrue();
});

it('throws for an unknown channel', function () {
    $log = logger_manager(logger_tmp_dir());

    expect(fn () => $log->channel('ghost'))->toThrow(InvalidArgumentException::class);
});

it('throws for an unsupported channel type', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'channels' => ['x' => ['channel' => 'fax']],
    ]);

    expect(fn () => $log->channel('x'))->toThrow(InvalidArgumentException::class);
});

it('telegram channel requires token and chat_id', function () {
    expect(fn () => new TelegramReportChannel([]))->toThrow(InvalidArgumentException::class);
    expect(fn () => new TelegramReportChannel(['token' => 't']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new TelegramReportChannel(['chat_id' => '1']))->toThrow(InvalidArgumentException::class);
    expect(new TelegramReportChannel(['token' => 't', 'chat_id' => '1']))->toBeInstanceOf(ReportChannelContract::class);
});

it('supports custom channel types through extendChannel()', function () {
    $spy = logger_channel_spy();
    $log = logger_manager(logger_tmp_dir(), [
        'channels' => ['alerts' => ['channel' => 'spy']],
    ]);

    $log->extendChannel('spy', fn (array $config, LogManager $manager) => $spy);
    $log->channel('alerts')->send(new Exception('hi'));

    expect($spy->sent)->toHaveCount(1);
});

// ---------------------------------------------------------------------
// report()
// ---------------------------------------------------------------------

it('report() sends the exception to every configured channel', function () {
    $one = logger_channel_spy();
    $two = logger_channel_spy();
    $log = logger_manager(logger_tmp_dir(), [
        'channels' => ['a' => ['channel' => 'one'], 'b' => ['channel' => 'two']],
    ]);

    $log->extendChannel('one', fn () => $one);
    $log->extendChannel('two', fn () => $two);

    $log->report(new Exception('everywhere'));

    expect($one->sent)->toHaveCount(1);
    expect($two->sent)->toHaveCount(1);
});

it('report() can target specific channels', function () {
    $one = logger_channel_spy();
    $two = logger_channel_spy();
    $log = logger_manager(logger_tmp_dir(), [
        'channels' => ['a' => ['channel' => 'one'], 'b' => ['channel' => 'two']],
    ]);

    $log->extendChannel('one', fn () => $one);
    $log->extendChannel('two', fn () => $two);

    $log->report(new Exception('only b'), ['b']);

    expect($one->sent)->toHaveCount(0);
    expect($two->sent)->toHaveCount(1);
});

it('report() swallows channel failures and still reaches the others', function () {
    $bad = logger_channel_spy(true);
    $good = logger_channel_spy();
    $log = logger_manager(logger_tmp_dir(), [
        'channels' => ['bad' => ['channel' => 'bad'], 'good' => ['channel' => 'good']],
    ]);

    $log->extendChannel('bad', fn () => $bad);
    $log->extendChannel('good', fn () => $good);

    $log->report(new Exception('survive'));

    expect($good->sent)->toHaveCount(1);
});

it('report() swallows configuration errors of a channel', function () {
    $log = logger_manager(logger_tmp_dir(), [
        'channels' => ['ghost' => ['channel' => 'does-not-exist']],
    ]);

    $log->report(new Exception('no crash'));

    expect(true)->toBeTrue();
});

it('report() with the default file channel writes to the log', function () {
    $dir = logger_tmp_dir();

    logger_manager($dir)->report(new DomainException('reported'));

    expect(logger_read($dir . '/app.log'))->toContain('error: reported');
});