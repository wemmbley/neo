<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Throwable;

interface ExceptionHandlerContract
{
    public function report(Throwable $e): void;

    public function render(Request $request, Throwable $e): Response;
}
