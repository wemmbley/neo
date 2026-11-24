<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use BadMethodCallException;
use Closure;

/**
 * Цепочка атрибутов группы: `Route::middleware('auth')->prefix('admin')->name('admin.')->group(...)`.
 *
 * @method static get(string $uri, Closure|array|string|null $action = null)
 */
class RouteRegistrar
{
    public const array PASSTHRU_ATTRIBUTES = [
        'prefix', 'middleware', 'withoutMiddleware', 'name', 'as', 'domain', 'controller', 'where',
    ];

    private const array VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'redirect', 'permanentRedirect', 'view', 'fallback'];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    public function __construct(protected Router $router) {}

    public function attribute(string $key, mixed $value = null, mixed $second = null): static
    {
        $key = $key === 'name' ? 'as' : $key;

        switch ($key) {
            case 'middleware':
                $this->attributes['middleware'] = [...($this->attributes['middleware'] ?? []), ...(is_array($value) ? $value : [$value])];
                break;
            case 'withoutMiddleware':
                $this->attributes['excluded_middleware'] = [...($this->attributes['excluded_middleware'] ?? []), ...(array) $value];
                break;
            case 'where':
                $this->attributes['where'] = [...($this->attributes['where'] ?? []), ...(is_array($value) ? $value : [$value => $second])];
                break;
            case 'prefix':
                $this->attributes['prefix'] = trim(trim((string) ($this->attributes['prefix'] ?? ''), '/') . '/' . trim((string) $value, '/'), '/');
                break;
            case 'as':
                $this->attributes['as'] = ($this->attributes['as'] ?? '') . $value;
                break;
            default:
                $this->attributes[$key] = $value;
        }

        return $this;
    }

    public function group(Closure|string $routes): static
    {
        $this->router->group($this->attributes, $routes);

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function resource(string $name, string $controller, array $options = []): PendingResourceRegistration
    {
        return $this->withinGroup(fn () => $this->router->resource($name, $controller, $options));
    }

    /** @param array<string, mixed> $options */
    public function apiResource(string $name, string $controller, array $options = []): PendingResourceRegistration
    {
        return $this->withinGroup(fn () => $this->router->apiResource($name, $controller, $options));
    }

    public function __call(string $method, array $parameters): mixed
    {
        if (in_array($method, self::VERBS, true)) {
            return $this->withinGroup(fn () => $this->router->{$method}(...$parameters));
        }

        if (in_array($method, self::PASSTHRU_ATTRIBUTES, true)) {
            return $this->attribute($method, ...$parameters);
        }

        throw new BadMethodCallException('Метод ' . static::class . "::{$method}() не существует.");
    }

    /**
     * Регистрирует маршрут сразу в нужной группе и возвращает Route (или PendingResourceRegistration).
     * Для ресурсов регистрация отложена до уничтожения объекта, поэтому группа удерживается
     * до этого момента вручную.
     */
    private function withinGroup(Closure $register): mixed
    {
        $result = null;

        $this->router->group($this->attributes, function () use ($register, &$result): void {
            $result = $register();

            if ($result instanceof PendingResourceRegistration) {
                $result->register();
            }
        });

        return $result;
    }
}
