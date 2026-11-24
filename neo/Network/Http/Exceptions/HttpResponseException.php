<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

use Neo\Network\Http\Response;
use RuntimeException;

/** Прерывает обработку запроса и сразу отдаёт готовый ответ (редирект из FormRequest и т. п.). */
class HttpResponseException extends RuntimeException
{
    public function __construct(private readonly Response $response)
    {
        parent::__construct('HTTP response exception.');
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
