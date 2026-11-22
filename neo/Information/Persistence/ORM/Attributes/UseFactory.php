<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Фабрика модели: `#[UseFactory(ProductFactory::class)]`. */
#[Attribute(Attribute::TARGET_CLASS)]
final class UseFactory
{
    /** @param class-string $factory */
    public function __construct(public readonly string $factory) {}
}
