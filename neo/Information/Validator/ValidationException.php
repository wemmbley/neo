<?php

declare(strict_types=1);

namespace Neo\Information\Validator;

final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Данные не прошли проверку.'
    ) {
        parent::__construct(
            $message . PHP_EOL . PHP_EOL . $this->formatErrors($errors),
            422
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(?string $field = null): ?string
    {
        $errors = $this->errors;

        $messages = $field === null
            ? (reset($errors) ?: [])
            : ($errors[$field] ?? []);

        return $messages[0] ?? null;
    }

    public function summary(): string
    {
        return $this->getMessage();
    }

    /**
     * Формирует подробное сообщение для разработчика.
     *
     * @param array<string, list<string>> $errors
     */
    private function formatErrors(array $errors): string
    {
        $lines = [];

        foreach ($errors as $field => $messages) {
            $lines[] = sprintf('[%s]', $field);

            foreach ($messages as $message) {
                $lines[] = '  - ' . $message;
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
