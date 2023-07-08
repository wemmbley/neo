<?php

declare(strict_types=1);

namespace App\Neo\Router;

use App\Neo\Helpers\FileSystem\File;
use App\Neo\Helpers\FileSystem\Path;
use App\Neo\Helpers\Primitives\Arr;
use App\Neo\Helpers\Primitives\Str;
use App\Neo\Helpers\Regex;
use App\Neo\Http\Request;
use App\Neo\Http\Response;
use App\Neo\Http\URL;
use Closure;

class Router
{
    protected static array $routes = [];
    protected static array $currentRoute = [];
    protected static bool $isRouteNotFound = true;

    public static function add(string $method, string $route, array|Closure $action): void
    {
        static::$routes[$method][$route]['action'] = $action;

        static::$currentRoute = [
            'method' => $method,
            'route' => $route
        ];
    }

    public static function addRegex(string $regex, array $regexParams): void
    {
        $method = static::$currentRoute['method'];
        $route = static::$currentRoute['route'];

        static::$routes[$method][$route]['regex'] = $regex;
        static::$routes[$method][$route]['regexParams'] = $regexParams;
    }

    public static function boot(): void
    {
        foreach (static::$routes as $method => $routes) {
            foreach ($routes as $routeName => $routeBody) {
                $regexUrl = false;

                if (isset($routeBody['regex']))
                    $regexUrl = Regex::match($routeBody['regex'])->string(URL::relative());

                $urlParams = [];

                if ($regexUrl) {
                    unset($regexUrl[0]);
                    $urlParams = Arr::combine($routeBody['regexParams'], Arr::reindex($regexUrl));
                }

                $urlParams['POST'] = Request::post();

                if ( ! URL::is($routeName) && empty($regexUrl) ) continue;
                if ( Request::method() !== $method ) continue;

                static::executeClosures($routeBody, $urlParams);
                static::executeControllers($routeBody, $urlParams);
            }
        }

        static::loadAssets();

        static::sendNotFound();
    }

    protected static function loadAssets()
    {
        $uri = Request::uri();

        if (Str::contains($uri, 'resources')) {
            $resourceFile = Path::abs('public/' . Str::removeChars($uri, 1));

            if (File::exists($resourceFile)) {
                Response::contentType(File::extension($resourceFile))
                    ->body(File::get($resourceFile))
                    ->send();
            }
        }
    }

    protected static function executeClosures(mixed $routeBody, array $params = []): void
    {
        if ( ! is_callable($routeBody['action'])) return;

        $routeBody['action']($params);

        static::setRouteFound();
    }

    protected static function executeControllers(mixed $routeBody, array $params = []): void
    {
        if (is_callable($routeBody['action'])) return;

        $controller = create_class($routeBody['action'][0]);

        Request::replaceInputBag($params);

        call_method($controller, $routeBody['action'][1]);

        static::setRouteFound();
    }

    protected static function setRouteFound(): void
    {
        static::$isRouteNotFound = false;
    }

    protected static function sendNotFound(): void
    {
        if (static::$isRouteNotFound) {
            Response::body('Page not found.')
                ->status(404)
                ->send();
        }
    }
}