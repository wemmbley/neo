<?php

declare(strict_types=1);

namespace App\Neo\Http;

use App\Neo\Helpers\FileSystem\Stream;
use App\Neo\Helpers\Primitives\Str;

class Request
{
    protected static array $inputBag = [];

    public static function input(string $name): mixed
    {
        if (Request::method() === Http::METHOD_GET)
            return (static::$inputBag[$name]) ?? null;

        foreach (static::$inputBag as $method => $value) {
            if (Request::method() === $method)
                return (static::$inputBag[$method][$name]) ?? null;
        }

        return null;
    }

    public static function all(): array
    {
        return static::$inputBag;
    }

    public static function insertInput(string $name, string $value): void
    {
        static::$inputBag[$name] = $value;
    }

    public static function replaceInputBag(array $inputs): void
    {
        static::$inputBag = $inputs;
    }

    public static function get(string $input = '')
    {
        if ( ! empty($input))
            return $_GET[$input] ?? null;

        return (object) $_GET;
    }

    public static function post(string $input = '')
    {
        if ( ! empty($input))
            return $_POST[$input] ?? null;

        return (object) $_POST;
    }

    public static function server(string $input = '')
    {
        if ( ! empty($input))
            return $_SERVER[$input] ?? null;

        return (object) $_SERVER;
    }

    public static function body(): bool|string
    {
        return Stream::input();
    }

    public static function method(): string
    {
        return static::server('REQUEST_METHOD');
    }

    public static function isAjax(): bool
    {
        if( ! is_null(static::server('HTTP_X_REQUESTED_WITH'))
            && Str::lower(static::server('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest')
            return true;

        return false;
    }

    public static function isPost(): bool
    {
        return Http::isGet(static::method());
    }

    public static function isGet(): bool
    {
        return Http::isPost(static::method());
    }

    public static function isPut(): bool
    {
        return Http::isPut(static::method());
    }

    public static function isDelete(): bool
    {
        return Http::isDelete(static::method());
    }

    public static function time(): int
    {
        return static::server('REQUEST_TIME');
    }

    public static function uri(): string
    {
        return static::server('REQUEST_URI');
    }

    public static function host(): string
    {
        return static::server('HTTP_HOST');
    }

    public static function ip(): string
    {
        return static::server('SERVER_ADDR');
    }

    public static function path(): string
    {
        return static::server('DOCUMENT_ROOT');
    }
}