<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Routing\UrlGenerator;

/**
 * Фабрика редиректов (хелпер redirect(), фасад Redirect::).
 *
 * Адреса «куда вернуть пользователя» (`intended`) берутся из сессии и записываются только
 * сервером из текущего запроса, поэтому подделать их из браузера нельзя.
 */
final class Redirector
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly RequestContextContract $context,
    ) {}

    public function to(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        return $this->make($this->url->to($path, [], $secure), $status, $headers);
    }

    private function make(string $url, int $status, array $headers): RedirectResponse
    {
        $redirect = new RedirectResponse($url, $status, $headers);
        $request = $this->context->isEntered() ? $this->context->request() : null;

        return $request instanceof Request ? $redirect->setRequest($request) : $redirect;
    }

    /** Редирект на внешний адрес без преобразований. Не передавайте сюда ввод пользователя. */
    public function away(string $path, int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->make($path, $status, $headers);
    }

    public function secure(string $path, int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->to($path, $status, $headers, true);
    }

    /** @param array<string|int, mixed> $parameters */
    public function route(string $name, array $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->make($this->url->route($name, $parameters), $status, $headers);
    }

    public function back(int $status = 302, array $headers = [], string|false $fallback = false): RedirectResponse
    {
        return $this->make($this->url->previous($fallback), $status, $headers);
    }

    public function refresh(int $status = 302, array $headers = []): RedirectResponse
    {
        $request = $this->context->request();

        return $this->make($request instanceof Request ? $request->fullUrl() : '/', $status, $headers);
    }

    /** Запоминает текущий адрес и отправляет на $path (например, на логин). */
    public function guest(string $path, int $status = 302, array $headers = []): RedirectResponse
    {
        $request = $this->context->request();

        if ($request instanceof Request && $request->isMethod('GET') && !$request->expectsJson() && $request->hasSession()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return $this->to($path, $status, $headers);
    }

    /** Возвращает на запомненный адрес (или на $default) и забывает его. */
    public function intended(string $default = '/', int $status = 302, array $headers = []): RedirectResponse
    {
        $request = $this->context->request();
        $target = null;

        if ($request instanceof Request && $request->hasSession()) {
            $stored = $request->session()->pull('url.intended');
            $target = is_string($stored) ? $stored : null;
        }

        return $target !== null ? $this->make($target, $status, $headers) : $this->to($default, $status, $headers);
    }
}
