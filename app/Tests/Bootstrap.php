<?php

declare(strict_types=1);

use Neo\Diagnostic\Testing\Runner;
use Neo\Foundation\Kernel\Kernel;

/**
 * Изоляция глобального приложения между тестами:
 * что бы тест ни сделал с Kernel (new Kernel, setInstance(null)),
 * следующий тест получит то же приложение, что было до него.
 */
$savedKernel = null;

Runner::instance()->globalBeforeEach(function () use (&$savedKernel): void {
    try {
        $savedKernel = Kernel::getInstance();
    } catch (LogicException) {
        $savedKernel = null;
    }
});

Runner::instance()->globalAfterEach(function () use (&$savedKernel): void {
    Kernel::setInstance($savedKernel);
});