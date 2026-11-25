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
        $cleanPath = ltrim(str_replace('\\', '/', $path), '/');

        return \Neo\Network\Http\Facades\URL::to("/Resources/{$cleanPath}");
    }
}