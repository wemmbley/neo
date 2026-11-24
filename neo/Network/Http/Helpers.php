<?php

declare(strict_types=1);

use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\Exceptions\HttpResponseException;
use Neo\Network\Http\Exceptions\NotFoundHttpException;
use Neo\Network\Http\Redirector;
use Neo\Network\Http\RedirectResponse;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\ResponseFactory;
use Neo\Network\Http\Routing\UrlGenerator;
use Neo\Network\Session\SessionManager;
use Neo\Security\Csrf\CsrfTokenManager;
use Neo\Security\Headers\CspNonce;
use Neo\Security\Xss\Escaper;

/** Текущий запрос; `request('key', 'default')` читает входное поле. */
if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = kernel(RequestContextContract::class)->request();

        if ($key === null || !$request instanceof Request) {
            return $request;
        }

        return $request->input($key, $default);
    }
}

/** `response()` даёт фабрику, `response('text', 201)` готовый Response. */
if (!function_exists('response')) {
    function response(mixed $content = null, int $status = 200, array $headers = []): ResponseFactory|Response
    {
        $factory = kernel(ResponseFactory::class);

        return $content === null && func_num_args() === 0 ? $factory : $factory->make($content ?? '', $status, $headers);
    }
}

if (!function_exists('redirect')) {
    function redirect(?string $to = null, int $status = 302, array $headers = [], ?bool $secure = null): Redirector|RedirectResponse
    {
        $redirector = kernel(Redirector::class);

        return $to === null ? $redirector : $redirector->to($to, $status, $headers, $secure);
    }
}

if (!function_exists('back')) {
    function back(int $status = 302, array $headers = [], string|false $fallback = false): RedirectResponse
    {
        return kernel(Redirector::class)->back($status, $headers, $fallback);
    }
}

if (!function_exists('to_route')) {
    function to_route(string $route, array $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return kernel(Redirector::class)->route($route, $parameters, $status, $headers);
    }
}

if (!function_exists('route')) {
    function route(string $name, array $parameters = [], bool $absolute = true): string
    {
        return kernel(UrlGenerator::class)->route($name, $parameters, $absolute);
    }
}

if (!function_exists('url')) {
    function url(?string $path = null, array $parameters = [], ?bool $secure = null): UrlGenerator|string
    {
        $generator = kernel(UrlGenerator::class);

        return $path === null ? $generator : $generator->to($path, $parameters, $secure);
    }
}

if (!function_exists('asset')) {
    function asset(string $path, ?bool $secure = null): string
    {
        return kernel(UrlGenerator::class)->asset($path, $secure);
    }
}

/** HTTP-ошибка: `abort(404)`, `abort(403, 'Нельзя')` или `abort($response)`. */
if (!function_exists('abort')) {
    function abort(int|Response $code, string $message = '', array $headers = []): never
    {
        if ($code instanceof Response) {
            throw new HttpResponseException($code);
        }

        throw $code === 404 ? new NotFoundHttpException($message, null, $headers) : new HttpException($code, $message, $headers);
    }
}

if (!function_exists('abort_if')) {
    function abort_if(mixed $condition, int|Response $code, string $message = '', array $headers = []): void
    {
        if ($condition) {
            abort($code, $message, $headers);
        }
    }
}

if (!function_exists('abort_unless')) {
    function abort_unless(mixed $condition, int|Response $code, string $message = '', array $headers = []): void
    {
        if (!$condition) {
            abort($code, $message, $headers);
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return kernel(CsrfTokenManager::class)->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return kernel(CsrfTokenManager::class)->field();
    }
}

/** Скрытое поле подмены метода: `<form method="POST">{!! method_field('PUT') !!}`. */
if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . Escaper::attr(strtoupper($method)) . '">';
    }
}

/** Экранирование для HTML (защита от XSS). */
if (!function_exists('e')) {
    function e(mixed $value, bool $doubleEncode = true): string
    {
        return Escaper::html($value, $doubleEncode);
    }
}

if (!function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return kernel(CspNonce::class)->get();
    }
}

/** `session()` менеджер, `session('key', 'default')` чтение, `session(['key' => 'value'])` запись. */
if (!function_exists('session')) {
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        $session = kernel(SessionManager::class);

        return match (true) {
            $key === null => $session,
            is_array($key) => $session->put($key),
            default => $session->get($key, $default),
        };
    }
}

/** Значение поля из предыдущей отправки формы (после `back()->withInput()`). */
if (!function_exists('old')) {
    function old(?string $key = null, mixed $default = null): mixed
    {
        $input = kernel(SessionManager::class)->get('_old_input', []);

        return $key === null ? $input : \Neo\Information\Structures\Arr::get((array) $input, $key, $default);
    }
}
