<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Events;

use Neo\Information\Persistence\ORM\Model;

/**
 * Заглушка под будущий EventDispatcher. Вызывается после UPDATE для каждого изменённого
 * свойства с #[Watch(Event::class)]. Реализацию привяжите через Model::setWatchDispatcher().
 */
interface WatchDispatcher
{
    /** @param class-string $event */
    public function dispatch(string $event, Model $model, string $property, mixed $old, mixed $new): void;
}
