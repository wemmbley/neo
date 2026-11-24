<?php

declare(strict_types=1);

namespace Neo\Network\Http\Console;

use Neo\Foundation\Console\Commands\Command;
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
    protected string $signature = 'serve {--host=127.0.0.1 : Адрес} {--port=8000 : Порт} {--workers=4 : Число рабочих процессов} {--swoole : Использовать Swoole}';

    protected string $description = 'Запускает HTTP-сервер для разработки';

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $workers = max(1, (int) $this->option('workers'));

        if ($this->option('swoole')) {
            if (!extension_loaded('swoole') && !extension_loaded('openswoole')) {
                $this->error('Расширение swoole не установлено. Запустите без --swoole: движок работает и без него.');

                return self::FAILURE;
            }

            $this->info("Swoole: http://{$host}:{$port} (workers: {$workers})");

            (new SwooleServer(kernel(HttpKernel::class), ['host' => $host, 'port' => $port, 'workers' => $workers]))->start();

            return self::SUCCESS;
        }

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
