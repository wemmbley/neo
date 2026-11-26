<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Support;

use RuntimeException;

/** В таблицах нет данных для сценария (не выполнен seed). */
final class NoDataException extends RuntimeException {}
