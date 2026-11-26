<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Exceptions;

use RuntimeException;

class MarkdownException extends RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Markdown file not found: %s', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('Markdown file is not readable: %s', $path));
    }

    public static function malformedDirective(string $name, int $line): self
    {
        return new self(sprintf(
            'Malformed attributes in directive "::%s" on line %d. Expected key="value" pairs.',
            $name,
            $line,
        ));
    }
}
