<?php

declare(strict_types=1);

namespace Neo\Network\Http\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Foundation\Runtime\Lifecycle;
use Neo\Foundation\Runtime\Swoole\SwooleApi;
use Neo\Network\Http\HttpKernel;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Network\Http\Server\SwooleServer;
use Throwable;

/**
 * Запускает сервер разработки.
 *
 *   php cli serve                     встроенный сервер PHP (работает везде, расширения не нужны)
 *   php cli serve --swoole            Swoole (нужно расширение swoole), быстрее на нагрузке
 */
class ServeCommand extends Command
{
    protected string $signature = 'serve {--host=127.0.0.1 : Адрес} {--port=8000 : Порт} {--workers= : Число рабочих процессов (по умолчанию SWOOLE_WORKERS, иначе число ядер CPU)} {--mode= : Режим Swoole: process или base (по умолчанию SWOOLE_MODE)} {--swoole : Использовать Swoole или OpenSwoole}';

    protected string $description = 'Запускает HTTP-сервер для разработки';

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        if ($this->option('swoole')) {
            if (!extension_loaded('swoole') && !extension_loaded('openswoole')) {
                $this->error('Расширение swoole не установлено. Запустите без --swoole: движок работает и без него.');

                return self::FAILURE;
            }

            $options = [
                ...array_filter((array) config('runtime.swoole', []), static fn (mixed $value): bool => $value !== null),
                'host' => $host,
                'port' => $port,
            ];

            # Приоритет: опция --workers, затем SWOOLE_WORKERS (runtime.swoole.workers), затем число ядер CPU.
            $requested = $this->option('workers');

            if ($requested !== null && $requested !== '') {
                $options['workers'] = max(1, (int) $requested);
            } elseif (isset($options['workers'])) {
                $options['workers'] = max(1, (int) $options['workers']);
            } else {
                unset($options['workers']);
            }

            $mode = strtolower((string) $this->option('mode'));

            if ($mode !== '') {
                if (!in_array($mode, ['process', 'base'], true)) {
                    $this->error("Режим «{$mode}» не поддерживается: допустимо process или base.");

                    return self::FAILURE;
                }

                $options['mode'] = $mode;
            }

            $workers = $options['workers'] ?? SwooleApi::cpuNum();

            $this->fitDatabasePool($workers);

            $this->info("Swoole: http://{$host}:{$port} (workers: {$workers}, mode: " . ($options['mode'] ?? 'process') . ')');

            (new SwooleServer(kernel(HttpKernel::class), $options, kernel(Lifecycle::class)))->start();

            return self::SUCCESS;
        }

        $workers = max(1, (int) ($this->option('workers') ?: config('runtime.swoole.workers') ?: 4));

        $public = base_path('public');

        if (!is_file($public . '/index.php')) {
            $this->error("Не найден {$public}/index.php");

            return self::FAILURE;
        }

        $this->info("Встроенный сервер PHP: http://{$host}:{$port}  (Ctrl+C для остановки)");

        putenv('PHP_CLI_SERVER_WORKERS=' . $workers);

        $command = sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host . ':' . $port),
            escapeshellarg($public),
            escapeshellarg(__DIR__ . '/../Server/DevRouter.php'),
        );

        passthru($command, $code);

        return $code;
    }

    /**
     * Пул соединений создаётся в каждом воркере: если `workers × pool.max` не помещается в
     * `max_connections` MySQL, уменьшаем пул до запуска воркеров и говорим об этом.
     */
    private function fitDatabasePool(int $workers): void
    {
        try {
            $reports = kernel(DatabaseManager::class)->fitPools($workers);
        } catch (Throwable) {
            return; # БД не настроена или недоступна: сервер всё равно стартует, ошибка появится при первом запросе
        }

        foreach ($reports as $name => $r) {
            $line = sprintf(
                'БД [%s]: пул %d на воркер × %d воркеров = %d из %d соединений MySQL (резерв %d).',
                $name, $r['applied'], $r['workers'], $r['applied'] * $r['workers'], $r['server_max'], $r['reserve'],
            );

            if ($r['applied'] < $r['configured']) {
                $this->warn($line . " DB_POOL_MAX={$r['configured']} не помещается: уменьшено до {$r['applied']}. "
                    . 'Поднимите max_connections в MySQL или уменьшите число воркеров.');
            } else {
                $this->info($line);
            }
        }
    }
}
