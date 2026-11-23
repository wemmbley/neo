<?php

if (! function_exists('view')) {
    function view(string $view, array $data = [])
    {
        return \Neo\Presentation\View\View::render($view, $data);
    }
}
