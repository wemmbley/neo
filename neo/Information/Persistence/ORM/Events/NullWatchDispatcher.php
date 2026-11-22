<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Events;

use Neo\Information\Persistence\ORM\Model;

final class NullWatchDispatcher implements WatchDispatcher
{
    public function dispatch(string $event, Model $model, string $property, mixed $old, mixed $new): void {}
}
