<?php

declare(strict_types=1);

namespace Neo\Testing;

/**
 * Проваленная проверка `expect(...)`.
 *
 * Хранит строки «ожидалось» и «получено» для красивого вывода
 * и место в тесте, где проверка была вызвана.
 */
final class AssertionFailed extends \Exception
{
    public function __construct(
        string $message,
        public readonly ?string $expected = null,
        public readonly ?string $actual = null,
        public readonly string $testFile = '',
        public readonly int $testLine = 0,
    ) {
        parent::__construct($message);
    }
}
