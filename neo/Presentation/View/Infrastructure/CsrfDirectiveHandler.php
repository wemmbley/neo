<?php

namespace Neo\Presentation\View\Infrastructure;

use Neo\Presentation\View\Directives\Contracts\CsrfDirectiveContract;
use Neo\Security\Crypt\Crypt;

class CsrfDirectiveHandler implements CsrfDirectiveContract
{
    public function token(): string
    {
        return Crypt::token();
    }
}