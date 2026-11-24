<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @debug (...) ... @endDebug */
class DebugDirective extends BasicDirective
{
    public string $directive = '@debug';
    public ?string $directiveEnd = '@endDebug';

    public function render(string $expression): string
    {
        if(!function_exists('env')) {
            return '';
        }

        // $expression returns ''. everytime. bug.

        return env('APP_DEBUG', false) ? $expression : '';
    }

    public function close(): ?string
    {
        return null;
    }
}