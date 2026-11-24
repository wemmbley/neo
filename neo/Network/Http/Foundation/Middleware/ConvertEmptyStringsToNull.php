<?php

declare(strict_types=1);

namespace Neo\Network\Http\Foundation\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/** Пустые строки во вводе становятся null (удобно для nullable-правил и БД). */
class ConvertEmptyStringsToNull
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->transformInput(static fn (mixed $value): mixed => $value === '' ? null : $value);

        return $next($request);
    }
}
