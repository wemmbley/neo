<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing\Controllers;

/**
 * Контроллер объявляет свои middleware статическим методом (Laravel 11+):
 *
 *   class PostController implements HasMiddleware
 *   {
 *       public static function middleware(): array
 *       {
 *           return ['auth', new Middleware('throttle:10,1', only: ['store'])];
 *       }
 *   }
 *
 * Метод статический, поэтому контроллер не создаётся до проверки middleware.
 */
interface HasMiddleware
{
    /** @return array<string|\Closure|Middleware> */
    public static function middleware(): array;
}
