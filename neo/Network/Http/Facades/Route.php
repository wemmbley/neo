<?php

declare(strict_types=1);

namespace Neo\Network\Http\Facades;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Network\Http\Contracts\RoutableResource;
use Neo\Network\Http\Routing\PendingResourceRegistration;
use Neo\Network\Http\Routing\Router;

/**
 * @method static Route get(string $uri, \Closure|array|string|null $action = null)
 * @method static Route post(string $uri, \Closure|array|string|null $action = null)
 * @method static Route put(string $uri, \Closure|array|string|null $action = null)
 * @method static Route patch(string $uri, \Closure|array|string|null $action = null)
 * @method static Route delete(string $uri, \Closure|array|string|null $action = null)
 * @method static Route options(string $uri, \Closure|array|string|null $action = null)
 * @method static Route any(string $uri, \Closure|array|string|null $action = null)
 * @method static Route matchmatch(array|string $methods, string $uri, \Closure|array|string|null $action = null)
 * @method static Route redirect(string $uri, string $destination, int $status = 302)
 * @method static Route permanentRedirect(string $uri, string $destination)
 * @method static Route view(string $uri, string $view, array $data = [], int $status = 200)
 * @method static Route fallback(\Closure|array|string $action)
 * @method static static group(array $attributes, \Closure|string $routes)
 * @method static PendingResourceRegistration|RoutableResource resource(string|RoutableResource $name, string|array|null $controller = null, array $options = [])
 */
#[FacadeOf(Router::class)]
class Route extends Facade {}
