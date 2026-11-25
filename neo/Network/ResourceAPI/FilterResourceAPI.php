<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Information\Persistence\ORM\Builder;
use Neo\Information\Structures\Str;

/**
 * Основа кастомных фильтров. Класс называется с постфиксом `Filter`, имя фильтра в запросе
 * берётся из имени класса: `PopularPostFilter` => `popular_post`.
 *
 *   final class PopularPostFilter extends FilterResourceAPI
 *   {
 *       public function apply(Builder $query, mixed $value): void
 *       {
 *           $query->where('views', '>', 1000);
 *       }
 *   }
 *
 * Фильтр создаётся контейнером один раз и не должен хранить данные запроса.
 */
abstract class FilterResourceAPI implements ResourceFilter
{
    public function name(): string
    {
        $name = Str::basename(static::class);

        return Str::snake(str_ends_with($name, 'Filter') ? substr($name, 0, -6) : $name);
    }

    abstract public function apply(Builder $query, mixed $value): void;
}
