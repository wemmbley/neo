<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

use Throwable;

/**
 * Тело записи не прошло `$rules` или `FormRequest`.
 * В отличие от остальных несёт несколько ошибок сразу (по одной на поле и правило).
 */
class ValidationFailedResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'validation_failed';

    protected string $defaultMessage = 'The given data was invalid.';

    /** @var list<array{code: string, field: string|null, message: string}> */
    private array $list = [];

    /**
     * @param array<string, list<string>|string> $errors поле => сообщения
     */
    public static function fromMessages(array $errors, ?Throwable $previous = null): static
    {
        $exception = new static('', null, $previous);

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $exception->list[] = ['code' => 'validation_failed', 'field' => (string) $field, 'message' => (string) $message];
            }
        }

        return $exception;
    }

    public function errors(): array
    {
        return $this->list !== [] ? $this->list : parent::errors();
    }
}
