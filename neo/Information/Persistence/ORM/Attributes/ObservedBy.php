<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/**
 * Наблюдатель модели: класс с методами по именам событий (saving, saved, creating, created,
 * updating, updated, deleting, deleted). Каждый метод получает модель.
 *
 *     #[ObservedBy(ProductObserver::class)]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ObservedBy
{
    /** @param class-string|list<class-string> $observers */
    public function __construct(public readonly string|array $observers) {}
}
