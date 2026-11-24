<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/** Объект, который умеет сам превратиться в Response (вернули из контроллера). */
interface Responsable
{
    public function toResponse(Request $request): Response;
}
