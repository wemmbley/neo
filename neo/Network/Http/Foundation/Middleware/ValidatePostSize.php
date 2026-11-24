<?php

declare(strict_types=1);

namespace Neo\Network\Http\Foundation\Middleware;

use Closure;
use Neo\Network\Http\Exceptions\HttpException;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Отвечает 413, если Content-Length больше лимита. Лимит: `http.max_body_size` (байты),
 * по умолчанию post_max_size из php.ini. В классическом SAPI при превышении PHP молча
 * опустошает $_POST, это middleware превращает «пустую форму» в понятную ошибку.
 */
class ValidatePostSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = $this->maxBytes();
        $length = $request->contentLength();

        if ($max > 0 && $length !== null && $length > $max) {
            throw new HttpException(413, 'Payload Too Large');
        }

        return $next($request);
    }

    protected function maxBytes(): int
    {
        $configured = config('http.max_body_size');

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        $value = trim((string) ini_get('post_max_size'));

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
