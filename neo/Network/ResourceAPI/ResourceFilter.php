<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Information\Persistence\ORM\Builder;

/**
 * Кастомный фильтр ресурса. Подключается через `$filters` ресурса, вызывается из запроса
 * по имени: `GET /posts?filters[popular]=1` (имя: {@see name()}).
 *
 * Свой фильтр наследуйте от {@see FilterResourceAPI}, он уже реализует этот контракт.
 */
interface ResourceFilter
{
    /** Имя фильтра в `filters[...]`. */
    public function name(): string;

    /**
     * Дополнить запрос. Значение приходит как есть (строка или массив из запроса):
     * проверяйте его сами.
     */
    public function apply(Builder $query, mixed $value): void;
}
