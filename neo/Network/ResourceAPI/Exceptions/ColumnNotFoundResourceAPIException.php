<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Колонка не найдена, скрыта или не разрешена для операции.
 * Сообщение одно и то же для всех трёх случаев: по разнице ответов нельзя узнать про скрытое поле. */
class ColumnNotFoundResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'column_not_found';

    protected string $defaultMessage = 'The requested field is not available.';
}
