<?php

declare(strict_types=1);

namespace App\Neo\Http;

use App\Neo\Helpers\MimeTypes\Json;
use App\Neo\MimeTypes\Mime;

/**
 * Code example
 *
 * <code>
 *      // send custom output
 *      Response::body( Json::encode(['name' => 'Rustam']) )
 *               ->status(201)
 *               ->contentType('json')
 *               ->header('X-Powered', 'by Neo')
 *               ->send();
 *
 *      // or json output
 *      Response::json(['error' => 'page not found'])
 *                ->status(404)
 *                ->send();
 *
 *      // redirect
 *      Response::redirect('https://neo.com')
 *                ->send();
 * </code>
 */
class Response
{
    protected static array $headers = [];
    protected static string $method = '';
    protected static string $body = '';

    public static function body(string $content = ''): static
    {
        static::$body = $content;

        return new static;
    }

    public static function json(array $content = []): static
    {
        static::$body = Json::encode($content);
        static::contentType('json');

        return new static;
    }

    public static function contentType(string $type): static
    {
        static::$headers[] = sprintf('Content-Type: %s', Mime::extension($type));

        return new static;
    }

    public static function method(string $method): static
    {
        self::$method = $method;

        return new static;
    }

    public static function header(string $name, string $value): static
    {
        static::$headers[] = sprintf('%s: %s', $name, $value);

        return new static;
    }

    public static function status(int $statusCode = 200): static
    {
        header(sprintf('HTTP/1.1 %s %s', $statusCode, Http::statusText($statusCode)));

        return new static;
    }

    public static function send(): void
    {
        foreach (static::$headers as $header) {
            header($header);
        }

        echo static::$body;

        die;
    }

    public static function redirect(string $to): static
    {
        static::$headers[] = sprintf('Location: %s', $to);

        return new static;
    }
}