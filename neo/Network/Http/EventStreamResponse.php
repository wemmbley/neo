<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Closure;

/**
 * Server-Sent Events поверх StreamResponse.
 *
 *   return response()->eventStream(function () {
 *       foreach ($items as $item) {
 *           yield $item;                                   // data: ...
 *           yield new StreamedEvent($item, event: 'tick'); // event: tick
 *       }
 *   });
 *
 * В конце потока отправляется событие `update` с данными `</stream>`
 * (можно поменять через $endEvent или отключить, передав null).
 */
class EventStreamResponse extends StreamResponse
{
    /** @param Closure(): iterable<mixed> $events */
    public function __construct(Closure $events, int $status = 200, array $headers = [], ?StreamedEvent $endEvent = new StreamedEvent('</stream>', 'update'))
    {
        parent::__construct(function (callable $write) use ($events, $endEvent): void {
            foreach ($events() as $event) {
                $write(($event instanceof StreamedEvent ? $event : new StreamedEvent($event))->toString());
            }

            if ($endEvent !== null) {
                $write($endEvent->toString());
            }
        }, $status, array_replace([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], $headers));
    }
}
