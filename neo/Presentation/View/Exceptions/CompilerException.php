<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Exceptions;

/**
 * Ошибка компиляции шаблона (незакрытая директива, битое выражение).
 * Сообщение содержит номер строки исходника.
 */
class CompilerException extends ViewException {}
