<?php

declare(strict_types=1);

namespace Neo\Validator;

/**
 * Данные не прошли проверку. Ядро отвечает на него статусом 422 и JSON
 * `{"message": "...", "errors": {"поле": ["сообщение", ...]}}`.
 *
 * Тот же массив ошибок можно передать в шаблон как `$errors`, и `@error('поле')` покажет сообщение.
 */
final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'Данные не прошли проверку.')
    {
        parent::__construct($message, 422);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Первое сообщение поля или, если поле не указано, вообще первое сообщение.
     */
    public function first(?string $field = null): ?string
    {
        $errors = $this->errors;
        $messages = $field === null ? (reset($errors) ?: []) : ($errors[$field] ?? []);

        return $messages[0] ?? null;
    }

    public function summary(): string
    {
        $parts = [];
        foreach ($this->errors as $field => $messages) {
            $parts[] = $field . ': ' . implode(' ', $messages);
        }
        return $this->getMessage() . ' ' . implode('; ', $parts);
    }
}
