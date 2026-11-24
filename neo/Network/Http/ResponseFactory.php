<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Closure;

/**
 * Фабрика ответов: хелпер response() и фасад Response::.
 *
 *   return response()->json(['ok' => true], 201);
 *   return response()->download($path, 'report.pdf');
 *   return response()->noContent();
 */
final class ResponseFactory
{
    public function __construct(private readonly Redirector $redirector) {}

    public function make(mixed $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    public function noContent(int $status = 204, array $headers = []): Response
    {
        return new Response('', $status, $headers);
    }

    /** @param array<string, mixed> $data */
    public function view(string $view, array $data = [], int $status = 200, array $headers = []): ViewResponse
    {
        return new ViewResponse($view, $data, $status, $headers);
    }

    public function json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse
    {
        return new JsonResponse($data, $status, $headers, $options);
    }

    /** @param Closure(callable(string): void): mixed $callback */
    public function stream(Closure $callback, int $status = 200, array $headers = []): StreamResponse
    {
        return new StreamResponse($callback, $status, $headers);
    }

    /** @param Closure(): iterable<mixed> $events */
    public function eventStream(Closure $events, array $headers = [], ?StreamedEvent $endEvent = new StreamedEvent('</stream>', 'update')): EventStreamResponse
    {
        return new EventStreamResponse($events, 200, $headers, $endEvent);
    }

    public function file(string $path, array $headers = []): FileResponse
    {
        return new FileResponse($path, 200, $headers);
    }

    public function download(string $path, ?string $name = null, array $headers = []): FileDownloadResponse
    {
        return new FileDownloadResponse($path, $name, $headers);
    }

    public function redirectTo(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        return $this->redirector->to($path, $status, $headers, $secure);
    }

    /** @param array<string|int, mixed> $parameters */
    public function redirectToRoute(string $route, array $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->redirector->route($route, $parameters, $status, $headers);
    }
}
