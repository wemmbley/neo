<?php

use Neo\Debug\Dumper;

if (!function_exists('dump')) {
    function dump(mixed ...$vars): mixed
    {
        return Dumper::dump(...$vars);
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$vars)
    {
        Dumper::dd(...$vars);
    }
}