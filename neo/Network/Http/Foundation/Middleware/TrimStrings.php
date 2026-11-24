<?php

declare(strict_types=1);

namespace Neo\Network\Http\Foundation\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/** Обрезает пробелы по краям всех строк ввода (кроме паролей). */
class TrimStrings
{
    /** @var list<string> */
    protected array $except = ['current_password', 'password', 'password_confirmation'];

    public function handle(Request $request, Closure $next): Response
    {
        $except = $this->except;

        $request->transformInput(
            static fn (mixed $value, string|int $key): mixed => is_string($value) && !in_array($key, $except, true)
                ? preg_replace('~^[\s\x{FEFF}\x{200B}]+|[\s\x{FEFF}\x{200B}]+$~u', '', $value) ?? trim($value)
                : $value,
        );

        return $next($request);
    }
}
