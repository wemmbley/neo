<?php

declare(strict_types=1);

namespace App\Neo\Http;

use App\Neo\Helpers\Primitives\Arr;
use App\Neo\Helpers\Primitives\Str;

class URL
{
    /**
     * Get full request URL.
     *
     * @return string
     */
    public static function full(): string
    {
        return Request::host() . Request::uri();
    }

    /**
     * Get relative URL.
     *
     * @return string
     */
    public static function relative(): string
    {
        return Request::uri();
    }

    /**
     * Split relative URL.
     *
     * @return array
     */
    public static function split(): array
    {
        $uri = Str::toArray('/', Request::uri());
        $uri = Arr::reindex(Arr::filter($uri));

        return $uri;
    }

    /**
     * Check, is url is equal
     *
     * @param string $url
     * @return bool
     */
    public static function is(string $url): bool
    {
        return URL::relative() === $url;
    }
}