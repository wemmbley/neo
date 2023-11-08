<?php

declare(strict_types=1);

namespace App\App\Middlewares;

use App\Neo\Http\Response;
use App\Neo\Protector;

class UserMiddleware
{
    public static function checkCsrf(): void
    {
        if ( ! Protector::isCsrf()) {
            Response::status(403)
                ->send();
        }
    }
}

