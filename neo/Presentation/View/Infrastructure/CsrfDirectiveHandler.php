<?php

namespace Neo\Presentation\View\Infrastructure;

use Neo\Presentation\View\Directives\Contracts\CsrfDirectiveContract;
use Neo\Security\Csrf\CsrfTokenManager;

/**
 * Токен для @csrf берётся из CsrfTokenManager: он привязан к сессии пользователя,
 * и именно его проверяет middleware VerifyCsrfToken. Случайный Crypt::token() сюда
 * не подходит: такой токен проверка не примет.
 */
class CsrfDirectiveHandler implements CsrfDirectiveContract
{
    public function token(): string
    {
        return kernel(CsrfTokenManager::class)->token();
    }
}
