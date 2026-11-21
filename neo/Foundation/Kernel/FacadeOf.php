<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class FacadeOf
{
    public function __construct(public readonly string $abstract) {}
}
