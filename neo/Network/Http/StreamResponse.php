<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Closure;

/**
 * Ответ, который пишется по частям (большие выгрузки, длинные операции).
 *
 *   return new StreamResponse(function (callable $write): void {
 *       foreach ($rows as $row) { $write(json_encode($row) . "\n"); }
 *   });
 *
 * Callback получает функцию записи; адаптер сам решает, что она делает (echo + flush
 * в SAPI, Response::write() в Swoole).
 *
 * Важно в Swoole: внутри callback нельзя полагаться на глобальный вывод (echo, print):
 * пишите только через $write.
 */
class StreamResponse extends Response
{
    /** @param Closure(callable(string): void): mixed $callback */
    public function __construct(protected readonly Closure $callback, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        $this->headers->set('X-Accel-Buffering', 'no');
    }

    public function isStreamed(): bool
    {
        return true;
    }

    public function streamTo(callable $write): void
    {
        ($this->callback)($write);
    }

    public function getContent(): string
    {
        return '';
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);

        # Длина заранее неизвестна.
        $this->headers->remove('Content-Length');

        return $this;
    }
}
