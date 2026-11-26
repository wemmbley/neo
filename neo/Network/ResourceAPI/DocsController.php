<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Network\Http\JsonResponse;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Просмотрщик API: страница `/docs/api` и её данные `/docs/api/schema.json`.
 * Документация строится из самих ресурсов, поэтому не расходится с кодом.
 * Показывает только ресурсы, которые разрешает смотреть Policy `viewAny`.
 */
final class DocsController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ?ResourceAuthorizer $authorizer = null,
    ) {}

    /** Описание API: ресурсы, их схемы и общие параметры запроса. Из него строятся JSON и страница. */
    public function payload(Request $request): array
    {
        $resources = [];

        foreach ($this->registry->all() as $entry) {
            $resource = $entry['resource'];

            if ($this->authorizer !== null && !$this->authorizer->can('viewAny', $resource->modelClass())) {
                continue;
            }

            $relations = [];
            foreach ($resource->relations() as $name => $definition) {
                $related = $definition->related !== null ? $this->registry->forModel($definition->related) : null;

                if ($related !== null) {
                    $relations[] = ['name' => $name, 'resource' => $related->resourceName(), 'many' => $definition->isMany()];
                }
            }

            $resources[] = [
                'name' => $resource->resourceName(),
                'url' => $request->root() . $entry['uri'],
                'endpoints' => array_map(static fn (array $r) => [
                    'method' => $r['method'],
                    'uri' => $r['uri'],
                    'action' => $r['action'],
                ], $entry['routes']),
                'schema' => $resource->schema(),
                'relations' => $relations,
            ];
        }

        return [
            'resources' => $resources,
            'query' => [
                'operators' => ResourceAPI::OPERATORS,
                'null_last' => (bool) ResourceAPI::setting('null_last'),
                'relation_deep' => (int) ResourceAPI::setting('relation_deep'),
                'limits' => (array) ResourceAPI::setting('filters'),
                'pagination' => (array) ResourceAPI::setting('pagination'),
            ],
        ];
    }

    public function data(Request $request): JsonResponse
    {
        return new JsonResponse($this->payload($request));
    }

    /** Страница просмотрщика: шаблон `resourceapi::docs` (Blade + Quas + Wex), данные приходят сразу. */
    public function page(Request $request): string
    {
        return view('resourceapi::docs', ['docs' => $this->payload($request)]);
    }

    /**
     * Манифест именованных маршрутов для JS-клиента: имя => методы и URI.
     * ETag + Cache-Control: клиент делает условные запросы, кука не нужна.
     *
     * @param list<array{name: string, methods: list<string>, uri: string, resource: ?string}> $routes
     */
    public function manifest(Request $request, array $routes): Response
    {
        $named = [];

        foreach ($routes as $route) {
            $resource = $route['resource'] !== null ? $this->registry->forName($route['resource']) : null;

            if ($resource !== null && $this->authorizer !== null && !$this->authorizer->can('viewAny', $resource->modelClass())) {
                continue;
            }

            $named[$route['name']] = ['methods' => $route['methods'], 'uri' => $route['uri'], 'resource' => $route['resource']];
        }

        $body = json_encode(['root' => $request->root(), 'routes' => $named], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $etag = '"' . md5($body) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=60', 'Content-Type' => 'application/json'];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response($body, 200, $headers);
    }
}
