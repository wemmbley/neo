<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Exceptions;

use RuntimeException;

/**
 * Любая ошибка движка шаблонов: шаблон не найден, упал при выводе и т.д.
 */
class ViewException extends RuntimeException {}
