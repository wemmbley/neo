<?php

declare(strict_types=1);

namespace Neo\Console;

/**
 * Вывод в консоль: потоки, цвета, стили сообщений.
 *
 * Все настройки внешнего вида живут здесь, остальной код пакета
 * ничего не знает про ANSI-коды.
 */
class Output
{
    /** ANSI-коды стилей. */
    protected const array STYLES = [
        'info'    => '32',    // зелёный
        'comment' => '33',    // жёлтый
        'warn'    => '30;43', // чёрный на жёлтом
        'error'   => '37;41', // белый на красном
    ];

    /** @var resource */
    protected $stream;

    protected bool $colors;

    /**
     * @param resource|null $stream Поток вывода (по умолчанию STDOUT).
     * @param bool|null     $colors null = включить цвета, только если это TTY и нет NO_COLOR.
     */
    public function __construct($stream = null, ?bool $colors = null)
    {
        $this->stream = $stream ?? STDOUT;
        $this->colors = $colors ?? (stream_isatty($this->stream) && getenv('NO_COLOR') === false);
    }

    /** Раскрашивает текст стилем (или возвращает как есть, если цвета выключены). */
    public function paint(string $text, string $style): string
    {
        $code = static::STYLES[$style] ?? null;

        return ($this->colors && $code !== null) ? "\e[{$code}m{$text}\e[0m" : $text;
    }

    public function line(string $text = '', ?string $style = null): void
    {
        fwrite($this->stream, ($style === null ? $text : $this->paint($text, $style)) . PHP_EOL);
    }

    public function newLine(int $count = 1): void
    {
        fwrite($this->stream, str_repeat(PHP_EOL, $count));
    }

    public function info(string $text): void
    {
        $this->line($text, 'info');
    }

    public function comment(string $text): void
    {
        $this->line($text, 'comment');
    }

    public function warn(string $text): void
    {
        $this->line($text, 'warn');
    }

    public function error(string $text): void
    {
        $this->line($text, 'error');
    }
}