<?php

declare(strict_types=1);

namespace Neo\Network\Http\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Foundation\Runtime\Lifecycle;
use Neo\Foundation\Runtime\Swoole\SwooleApi;
use Neo\Network\Http\HttpKernel;
use Neo\Network\Http\Server\SwooleServer;

/**
 * Запускает сервер разработки.
 *
 *   php cli serve                     встроенный сервер PHP (работает везде, расширения не нужны)
 *   php cli serve --swoole            Swoole (нужно расширение swoole), быстрее на нагрузке
 */
class ServeCommand extends Command
{
    protected string $signature = 'serve {--host=127.0.0.1 : Адрес} {--port=8000 : Порт} {--workers= : Число рабочих процессов (по умолчанию SWOOLE_WORKERS, иначе число ядер CPU)} {--swoole : Использовать Swoole или OpenSwoole}';

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

            $workers = $options['workers'] ?? SwooleApi::cpuNum();

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
}
