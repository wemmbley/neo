<?php

if (! function_exists('view')) {
    function view(string $view, array $data = [])
    {
        return \Neo\Presentation\View\View::render($view, $data);
    }
}

if (! function_exists('resource')) {
    function resource(string $path): string
    {

        $url = (string) (config('view::resources.url') ?? '');

        return rtrim($url, '/') . '/' . ltrim($path, '/');
    }
}