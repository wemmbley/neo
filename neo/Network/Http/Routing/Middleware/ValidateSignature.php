<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing\Middleware;

use Closure;
use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\UrlGenerator;

/**
 * Пропускает только запросы с верной подписью ссылки (`URL::signedRoute()`), middleware `signed`.
 * `signed:relative` проверяет подпись относительной ссылки (без хоста).
 */
class ValidateSignature
{
    public function __construct(protected readonly UrlGenerator $url) {}

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if ($this->url->hasValidSignature($request, !in_array('relative', $args, true))) {
            return $next($request);
        }

        throw new HttpException(403, 'Invalid signature.');
    }
}
