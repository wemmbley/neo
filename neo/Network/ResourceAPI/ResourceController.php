<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Closure;
use Neo\Information\Persistence\ORM\Exceptions\ModelNotFoundException;
use Neo\Information\Structures\Arr;
use Neo\Information\Validator\ValidationException;
use Neo\Information\Validator\Validator;
use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\JsonResponse;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\Router;
use Neo\Network\ResourceAPI\Exceptions\ActionStrictResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\ForbiddenResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\PayloadFieldNotAllowedResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\RecordNotFoundResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\ResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\ValidationFailedResourceAPIException;
use Neo\Network\Http\FormRequest;
use Neo\Security\Authentication\AuthenticationException;
use Neo\Security\Authorization\AuthorizationException;
use Throwable;

/**
 * Адаптер HTTP: регистрирует маршруты ресурса и обслуживает их. Логики выборки здесь нет
 * (её держат {@see ResourceQuery} и репозиторий), только запрос, ответ и авторизация.
 *
 * Ответы единого вида: `{status, code, data, [paginator], errors}`. Ошибки (в том числе
 * 401/403/404/405/429 соседних модулей на адресах API) приводятся к нему через {@see render()}.
 */
final class ResourceController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly OrmResourceRepository $repository,
        private readonly ?ResourceAuthorizer $authorizer = null,
    ) {}

    // ------------------------------------------------------------------
    // Маршруты
    // ------------------------------------------------------------------

    public function register(Router $router, ResourceAPI $resource): void
    {
        $name = $resource->resourceName();
        $routes = [];

        $add = function (string $methods, string $uri, string $action, Closure $handler) use ($router, $name, &$routes): void {
            $route = $router->match(explode('|', $methods), $uri, $handler)->name($name . '.' . $action);
            $route->setAction([...$route->getAction(), 'resource_api' => $name]);

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[] = ['method' => $method, 'uri' => $route->uri(), 'action' => $action, 'name' => $route->getName()];
                }
            }
        };

        # Статический /schema идёт раньше /{id}, поэтому имя schema не может стать идентификатором.
        $add('GET', "/{$name}/schema", 'schema', fn (Request $request) => $this->schema($resource));

        foreach ($resource->enabledActions() as $action) {
            match ($action) {
                'index' => $add('GET', "/{$name}", 'index', fn (Request $request) => $this->index($resource, $request)),
                'store' => $add('POST', "/{$name}", 'store', fn (Request $request) => $this->store($resource, $request)),
                'show' => $add('GET', "/{$name}/{id}", 'show', fn (Request $request, string $id) => $this->show($resource, $request, $id)),
                'update' => $add('PUT|PATCH', "/{$name}/{id}", 'update', fn (Request $request, string $id) => $this->update($resource, $request, $id)),
                'destroy' => $add('DELETE', "/{$name}/{id}", 'destroy', fn (Request $request, string $id) => $this->destroy($resource, $id)),
            };
        }

        $schemaUri = $routes[0]['uri'];
        $this->registry->register($resource, substr($schemaUri, 0, -strlen('/schema')), $routes);
    }

    // ------------------------------------------------------------------
    // Действия
    // ------------------------------------------------------------------

    public function index(ResourceAPI $resource, Request $request): JsonResponse
    {
        $this->guard($resource, 'index');
        $this->authorize('viewAny', $resource->modelClass());

        $query = ResourceQuery::parse($resource, $request->query(), $this->registry, $this->authorizer);
        $paginator = $this->repository->paginate($resource, $query, $request->url(), Arr::except($request->query(), ['page']));

        return $this->ok($paginator->items(), 200, ['paginator' => $paginator->paginator()]);
    }

    public function show(ResourceAPI $resource, Request $request, string $id): JsonResponse
    {
        $this->guard($resource, 'show');

        $query = ResourceQuery::parse($resource, Arr::only($request->query(), ['include', 'fields']), $this->registry, $this->authorizer);
        $model = $this->repository->find($resource, $id, $query) ?? throw new RecordNotFoundResourceAPIException();

        $this->authorize('view', $model);

        return $this->ok($this->repository->present($resource, $model, $query));
    }

    public function store(ResourceAPI $resource, Request $request): JsonResponse
    {
        $this->guard($resource, 'store');
        $this->authorize('create', $resource->modelClass());

        $model = $this->repository->create($resource, $this->payload($resource, $request, false));

        return $this->ok(
            $this->repository->present($resource, $model),
            201,
            [],
            ['Location' => $request->url() . '/' . $model->getKey()],
        );
    }

    public function update(ResourceAPI $resource, Request $request, string $id): JsonResponse
    {
        $this->guard($resource, 'update');

        $model = $this->repository->find($resource, $id) ?? throw new RecordNotFoundResourceAPIException();
        $this->authorize('update', $model);

        $model = $this->repository->update($resource, $model, $this->payload($resource, $request, $request->method() === 'PATCH'));

        return $this->ok($this->repository->present($resource, $model));
    }

    public function destroy(ResourceAPI $resource, string $id): Response
    {
        $this->guard($resource, 'destroy');

        $model = $this->repository->find($resource, $id) ?? throw new RecordNotFoundResourceAPIException();
        $this->authorize('delete', $model);

        $this->repository->delete($model);

        return new Response('', 204);
    }

    public function schema(ResourceAPI $resource): JsonResponse
    {
        $this->authorize('viewAny', $resource->modelClass());

        return $this->ok($resource->schema());
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    private function guard(ResourceAPI $resource, string $action): void
    {
        if (!$resource->allows($action)) {
            throw new ActionStrictResourceAPIException();
        }
    }

    /** @param class-string|object $subject */
    private function authorize(string $ability, string|object $subject): void
    {
        $this->authorizer?->authorize($ability, $subject);
    }

    /**
     * Тело записи: только поля из `$fillable` модели, видимые и с правилом в `$rules`.
     * Остальное отклоняется, а не отбрасывается молча. Возвращает проверенные данные по колонкам.
     *
     * @return array<string, mixed>
     */
    private function payload(ResourceAPI $resource, Request $request, bool $partial): array
    {
        $body = $request->isJson() ? $request->json() : $request->post();
        $body = is_array($body) ? $body : [];
        $writable = $resource->writable();
        $data = [];

        foreach ($body as $key => $value) {
            $column = $writable[(string) $key] ?? null;

            if ($column === null) {
                throw new PayloadFieldNotAllowedResourceAPIException('', (string) $key);
            }

            $data[$column] = $value;
        }

        $rules = $resource->ruleSet();
        $messages = $attributes = [];
        $definition = $resource->rulesDefinition();

        if (is_string($definition)) {
            /** @var FormRequest $form */
            $form = $definition::createFrom($request);

            if (!$form->authorize()) {
                throw new ForbiddenResourceAPIException();
            }

            [$messages, $attributes] = [$form->messages(), $form->attributes()];
        }

        # PATCH проверяет только присланное: required для отсутствующих полей не действует.
        if ($partial) {
            $rules = array_filter($rules, static fn ($key) => array_key_exists(explode('.', (string) $key)[0], $data), ARRAY_FILTER_USE_KEY);
        }

        $validator = Validator::make($data, $rules, $messages, $attributes);

        if ($validator->fails()) {
            $names = [];
            foreach ($resource->exposed() as $column => $info) {
                $names[$column] = $info['name'];
            }

            $errors = [];
            foreach ($validator->errors() as $field => $list) {
                $head = explode('.', (string) $field, 2);
                $errors[implode('.', [$names[$head[0]] ?? $head[0], ...array_slice($head, 1)])] = $list;
            }

            throw ValidationFailedResourceAPIException::fromMessages($errors);
        }

        return array_intersect_key($validator->validate(), $data);
    }

    /**
     * @param mixed $data
     * @param array<string, mixed> $extra дополнительные блоки ответа (`paginator`)
     * @param array<string, string> $headers
     */
    private function ok(mixed $data, int $status = 200, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'ok', 'code' => $status, 'data' => $data] + $extra + ['errors' => []],
            $status,
            $headers,
        );
    }

    // ------------------------------------------------------------------
    // Ошибки
    // ------------------------------------------------------------------

    /**
     * Единый формат ошибок для адресов API. Вернёт null для остальных запросов:
     * они обслуживаются обычным Handler.
     */
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($request->route()?->getAction('resource_api') === null && !$this->registry->ownsPath($request->getPathInfo())) {
            return null;
        }

        $headers = [];

        if ($e instanceof ResourceAPIException) {
            $status = $e->status();
            $errors = $e->errors();
            $headers = $e->headers();
        } elseif ($e instanceof ValidationException) {
            $status = 422;
            $errors = ValidationFailedResourceAPIException::fromMessages($e->errors())->errors();
        } elseif ($e instanceof ModelNotFoundException) {
            $status = 404;
            $errors = (new RecordNotFoundResourceAPIException())->errors();
        } elseif ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            [$code, $message] = match (true) {
                $e instanceof AuthenticationException => ['unauthenticated', 'Unauthenticated.'],
                $e instanceof AuthorizationException => ['forbidden', $e->getMessage()],
                $status === 404 => ['not_found', 'The requested resource was not found.'],
                $status === 405 => ['action_not_allowed', 'The action is not allowed for this resource.'],
                $status === 429 => ['too_many_requests', 'Too many requests.'],
                default => ['http_error', $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.'],
            };
            $errors = [['code' => $code, 'field' => null, 'message' => $message]];
        } else {
            $status = 500;
            $errors = [['code' => 'server_error', 'field' => null, 'message' => ResourceAPI::setting('debug') ? $e->getMessage() : 'Server error.']];
        }

        return new JsonResponse(['status' => 'error', 'code' => $status, 'errors' => $errors], $status, $headers);
    }
}
