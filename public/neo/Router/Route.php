<?php

declare(strict_types=1);

namespace App\Neo\Router;

use App\Neo\Helpers\Primitives\Arr;
use App\Neo\Helpers\Primitives\Str;
use App\Neo\Helpers\Regex;
use App\Neo\Http\Http;
use Closure;

class Route
{
    protected static string $method = '';
    protected static string $uri = '';

    public static function get(string $uri, array|Closure $action): static
    {
        Router::add(Http::METHOD_GET, $uri, $action);

        static::$uri = $uri;

        return new static;
    }

    public static function post(string $uri, array|Closure $action): static
    {
        Router::add(Http::METHOD_POST, $uri, $action);

        static::$uri = $uri;

        return new static;
    }

    public static function put(string $uri, array|Closure $action): static
    {
        Router::add(Http::METHOD_PUT, $uri, $action);

        static::$uri = $uri;

        return new static;
    }

    public static function delete(string $uri, array|Closure $action): static
    {
        Router::add(Http::METHOD_DELETE, $uri, $action);

        static::$uri = $uri;

        return new static;
    }

    public static function where(array $regexParams): void
    {
        $regex = '/';
        $expUri = Str::split(static::$uri, '/');

        foreach ($expUri as $uriParam) {
            $uriMatch = Regex::match('/\{(\w+)\}/')->string($uriParam);

            if ( ! isset($uriMatch[1])) {
                $regex .= $uriParam;
                continue;
            }

            if ( ! $uriMatch) continue;

            foreach ($regexParams as $regexName => $regexParam) {
                $uriName = $uriMatch[1];

                if ($uriName === $regexName) {
                    $regex .= '\/(' . $regexParam . ')';
                }
            }
        }

        $regex .= '/';

        Router::addRegex($regex, Arr::keys($regexParams));
    }
}