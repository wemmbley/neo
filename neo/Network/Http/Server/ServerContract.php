<?php

declare(strict_types=1);

namespace Neo\Network\Http\Server;

/**
 * Долгоживущий HTTP-сервер. Классический SAPI (FPM, Apache, `php -S`) сервером в этом смысле
 * не является: он запускает index.php на каждый запрос и использует {@see SapiEmitter}.
 */
interface ServerContract
{
    /** Запускает сервер и блокирует процесс до остановки. */
    public function start(): void;

    public function stop(): void;
}
