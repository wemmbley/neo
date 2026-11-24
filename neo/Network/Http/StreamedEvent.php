<?php

declare(strict_types=1);

namespace Neo\Network\Http;

/** Одно событие Server-Sent Events. */
final readonly class StreamedEvent
{
    public function __construct(
        public mixed $data = '',
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null,
    ) {}

    /** Строка для отправки в поток. Переводы строк в данных разбиваются на несколько `data:`. */
    public function toString(): string
    {
        $out = '';

        if ($this->event !== null) {
            $out .= 'event: ' . self::line($this->event) . "\n";
        }

        if ($this->id !== null) {
            $out .= 'id: ' . self::line($this->id) . "\n";
        }

        if ($this->retry !== null) {
            $out .= 'retry: ' . max(0, $this->retry) . "\n";
        }

        $data = is_string($this->data) || is_numeric($this->data) || $this->data instanceof \Stringable
            ? (string) $this->data
            : json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (preg_split('/\R/u', $data) ?: [''] as $line) {
            $out .= 'data: ' . $line . "\n";
        }

        return $out . "\n";
    }

    /** Имя события и id не могут содержать переводов строк (иначе можно подсунуть чужое поле). */
    private static function line(string $value): string
    {
        return (string) preg_replace('/[\r\n]+/', ' ', $value);
    }
}
