<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Событие, которое будет вызвано при изменении свойства (для будущего EventDispatcher). */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Watch
{
    /** @param class-string $event */
    public function __construct(public readonly string $event) {}
}
