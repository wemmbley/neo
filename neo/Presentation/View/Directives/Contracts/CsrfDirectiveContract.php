<?php

namespace Neo\Presentation\View\Directives\Contracts;

/**
 * Этот контракт нужен для реализации хэша на основании соли.
 * Как правило, она генерируется раз, и пишется в .env в параметр
 * APP_KEY, но у разных приложений - разные механики.
 */
interface CsrfDirectiveContract
{
    public function token(): string;
}