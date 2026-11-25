<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Базовое исключение ResourceAPI. От него наследуются все остальные.
 *
 * Не зависит от HTTP: несёт статус, стабильный строковый код и поле, а в ответ его превращает
 * адаптер (ResourceAPIServiceProvider регистрирует рендер в едином Handler). Имя класса в ответ
 * не попадает: клиент видит только `code`.
 */
class ResourceAPIException extends RuntimeException
{
    /** HTTP-статус ответа. */
    protected int $status = 400;

    /** Стабильный код в ответе (`errors[].code`). */
    protected string $errorCode = 'resource_api_error';

    protected string $defaultMessage = 'Resource API error.';

    /** @param array<string, string> $headers */
    public function __construct(
        string $message = '',
        protected readonly ?string $field = null,
        ?Throwable $previous = null,
        protected readonly array $headers = [],
    ) {
        parent::__construct($message !== '' ? $message : $this->defaultMessage, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Ошибки для ответа. Обычно одна, при ошибке валидации несколько.
     *
     * @return list<array{code: string, field: string|null, message: string}>
     */
    public function errors(): array
    {
        return [['code' => $this->errorCode, 'field' => $this->field, 'message' => $this->getMessage()]];
    }
}
