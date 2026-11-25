<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Information\Persistence\ORM\Attributes\Relations\BelongsTo;
use Neo\Information\Persistence\ORM\Attributes\Relations\BelongsToMany;
use Neo\Information\Persistence\ORM\Attributes\Relations\HasMany;
use Neo\Information\Persistence\ORM\Attributes\Relations\HasOne;
use Neo\Network\ResourceAPI\Exceptions\ColumnNotFoundResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\FilterLimitExceededResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\IncorrectRequestParamsResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\OperatorNotAllowedResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\PerPageLimitReachedResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\RelationDepthExceededResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\RelationNotAllowedResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\SortByManyRelationResourceAPIException;

/**
 * Разобранный и проверенный запрос: AST, который понимает любой адаптер хранилища
 * (сейчас {@see OrmResourceRepository}). Ядро ничего не знает ни про HTTP, ни про ORM:
 * на входе массив параметров (`filters`, `sort`, `page`, `include`, `fields`, `search`),
 * на выходе неизменяемый объект или исключение ResourceAPI.
 *
 * Узлы фильтра:
 *   group    ['type' => 'group', 'boolean' => 'and'|'or', 'items' => [...]]
 *   cond     ['type' => 'cond', 'column', 'type_name', 'op', 'value']
 *   relation ['type' => 'relation', 'relation', 'exists' => ?bool, 'group' => group]
 *
 * Значения приведены к типу колонки. Колонки в узлах настоящие (не переименованные).
 */
final class ResourceQuery
{
    private const array LIKE = ['$contains', '$notContains', '$containsc', '$notContainsc', '$startsWith', '$startsWithc', '$endsWith', '$endsWithc'];
    private const array LIST = ['$in', '$notIn'];
    private const array PAIR = ['$between', '$notBetween'];

    /**
     * @param array<string, mixed> $filters
     * @param list<array{0: ResourceFilter, 1: mixed}> $custom
     * @param list<array{relations: list<string>, column: string, direction: string}> $sorts
     * @param list<string> $include пути связей через точку
     * @param list<string>|null $fields колонки
     */
    public function __construct(
        public readonly array $filters,
        public readonly array $custom,
        public readonly array $sorts,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $include,
        public readonly ?array $fields,
        public readonly ?string $search,
        public readonly int $conditions,
    ) {}

    /** @param array<string, mixed> $params */
    public static function parse(
        ResourceAPI $resource,
        array $params,
        ResourceRegistry $registry,
        ?ResourceAuthorizer $authorizer = null,
    ): self {
        $ctx = new class($registry, $authorizer) {
            public int $conditions = 0;
            public array $custom = [];

            public function __construct(public ResourceRegistry $registry, public ?ResourceAuthorizer $authorizer) {}
        };

        [$page, $perPage] = self::page($resource, $params['page'] ?? null);

        $filters = ['type' => 'group', 'boolean' => 'and', 'items' => []];
        if (isset($params['filters'])) {
            if (!is_array($params['filters'])) {
                throw new IncorrectRequestParamsResourceAPIException('The filters parameter must be an array.', 'filters');
            }

            if ($params['filters'] !== [] && !$resource->filterEnabled()) {
                throw new IncorrectRequestParamsResourceAPIException('Filtering is disabled for this resource.', 'filters');
            }

            $filters = self::group($params['filters'], $resource, 0, true, $ctx);
        }

        return new self(
            $filters,
            $ctx->custom,
            self::sorts($resource, $params['sort'] ?? null, $ctx),
            $page,
            $perPage,
            self::includes($resource, $params['include'] ?? null, $ctx),
            self::fields($resource, $params['fields'] ?? null),
            self::search($resource, $params['search'] ?? null),
            $ctx->conditions,
        );
    }

    public function hasFilters(): bool
    {
        return $this->filters['items'] !== [] || $this->custom !== [];
    }

    // ------------------------------------------------------------------
    // page
    // ------------------------------------------------------------------

    /** @return array{0: int, 1: int} */
    private static function page(ResourceAPI $resource, mixed $page): array
    {
        $number = 1;
        $size = $resource->perPage();

        if ($page === null) {
            return [$number, $size];
        }

        if (!is_array($page)) {
            throw new IncorrectRequestParamsResourceAPIException('Use page[number] and page[size].', 'page');
        }

        if (isset($page['number'])) {
            $number = self::int($page['number'], 'page[number]');
        }

        if (isset($page['size'])) {
            $size = self::int($page['size'], 'page[size]');

            if ($size > (int) ResourceAPI::setting('pagination.max_per_page')) {
                throw new PerPageLimitReachedResourceAPIException(
                    'The maximum page size is ' . (int) ResourceAPI::setting('pagination.max_per_page') . '.',
                    'page[size]',
                );
            }
        }

        return [$number, $size];
    }

    private static function int(mixed $value, string $field): int
    {
        if ((!is_string($value) && !is_int($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new IncorrectRequestParamsResourceAPIException("The {$field} must be a positive integer.", $field);
        }

        return (int) $value;
    }

    // ------------------------------------------------------------------
    // filters
    // ------------------------------------------------------------------

    private static function group(array $node, ResourceAPI $resource, int $depth, bool $top, object $ctx): array
    {
        $items = [];

        foreach ($node as $key => $value) {
            $key = (string) $key;

            if ($key === '$or' || $key === '$and') {
                if (!is_array($value) || $value === []) {
                    throw new IncorrectRequestParamsResourceAPIException("{$key} expects a list of conditions.", $key);
                }

                $children = [];
                foreach ($value as $child) {
                    if (!is_array($child) || $child === []) {
                        throw new IncorrectRequestParamsResourceAPIException("{$key} expects a list of conditions.", $key);
                    }

                    $children[] = self::group($child, $resource, $depth, false, $ctx);
                }

                $items[] = ['type' => 'group', 'boolean' => $key === '$or' ? 'or' : 'and', 'items' => $children];
                continue;
            }

            if (str_starts_with($key, '$')) {
                throw new OperatorNotAllowedResourceAPIException('', $key);
            }

            if (isset($resource->customFilters()[$key])) {
                if (!$top) {
                    throw new IncorrectRequestParamsResourceAPIException('Custom filters cannot be used inside $or or $and.', $key);
                }

                self::count($ctx);
                $ctx->custom[] = [$resource->customFilters()[$key], $value];
                continue;
            }

            if (!is_array($value)) {
                throw new IncorrectRequestParamsResourceAPIException("Specify an operator, for example filters[{$key}][\$eq].", $key);
            }

            $exists = null;
            $rest = $value;

            if (array_key_exists('$exists', $value)) {
                if (!$resource->operatorAllowed('$exists')) {
                    throw new OperatorNotAllowedResourceAPIException('', $key);
                }

                $exists = self::bool($value['$exists'], $key);
                unset($rest['$exists']);
                self::count($ctx);
            }

            $isRelation = $exists !== null || array_filter(
                array_keys($rest),
                static fn ($k) => !str_starts_with((string) $k, '$') || in_array($k, ['$or', '$and'], true),
            ) !== [];

            if ($isRelation) {
                if ($exists === false && $rest !== []) {
                    throw new IncorrectRequestParamsResourceAPIException('$exists=false cannot have nested conditions.', $key);
                }

                [$related, $definition] = self::related($resource, $key, $ctx, 'filter');

                if ($depth + 1 > (int) ResourceAPI::setting('relation_deep')) {
                    throw new RelationDepthExceededResourceAPIException('', $key);
                }

                if (!$related->filterEnabled()) {
                    throw new RelationNotAllowedResourceAPIException('', $key);
                }

                $items[] = [
                    'type' => 'relation',
                    'relation' => $key,
                    'exists' => $exists,
                    'group' => self::group($rest, $related, $depth + 1, false, $ctx),
                ];
                continue;
            }

            $column = $resource->requireColumn($key, 'filter');
            $type = $resource->exposed()[$column]['type'];

            foreach ($rest as $operator => $argument) {
                $operator = (string) $operator;

                if (!$resource->operatorAllowed($operator)) {
                    throw new OperatorNotAllowedResourceAPIException('', $key);
                }

                self::count($ctx);
                $items[] = [
                    'type' => 'cond',
                    'column' => $column,
                    'op' => $operator,
                    'value' => self::argument($operator, $argument, $type, $key),
                ];
            }
        }

        return ['type' => 'group', 'boolean' => 'and', 'items' => $items];
    }

    private static function count(object $ctx): void
    {
        if (++$ctx->conditions > (int) ResourceAPI::setting('filters.max_conditions')) {
            throw new FilterLimitExceededResourceAPIException('The maximum number of conditions is ' . (int) ResourceAPI::setting('filters.max_conditions') . '.');
        }
    }

    private static function argument(string $operator, mixed $argument, string $type, string $field): mixed
    {
        if ($operator === '$null') {
            return self::bool($argument, $field);
        }

        if ($type === 'json' || (in_array($operator, self::LIKE, true) && $type !== 'string') || ($type === 'bool' && !in_array($operator, ['$eq', '$ne', '$in', '$notIn'], true))) {
            throw new IncorrectRequestParamsResourceAPIException("Operator {$operator} cannot be used with a {$type} field.", $field);
        }

        if (in_array($operator, self::LIST, true) || in_array($operator, self::PAIR, true)) {
            if (!is_array($argument) || $argument === [] || !array_is_list($argument)) {
                throw new IncorrectRequestParamsResourceAPIException("Operator {$operator} expects a list of values.", $field);
            }

            if (in_array($operator, self::PAIR, true) && count($argument) !== 2) {
                throw new IncorrectRequestParamsResourceAPIException("Operator {$operator} expects exactly two values.", $field);
            }

            if (count($argument) > (int) ResourceAPI::setting('filters.max_in_items')) {
                throw new FilterLimitExceededResourceAPIException('The maximum number of list items is ' . (int) ResourceAPI::setting('filters.max_in_items') . '.', $field);
            }

            return array_map(static fn ($v) => self::coerce($v, $type, $field), $argument);
        }

        return self::coerce($argument, $type, $field);
    }

    private static function coerce(mixed $value, string $type, string $field): mixed
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new IncorrectRequestParamsResourceAPIException("Invalid value for {$field}.", $field);
        }

        if (is_string($value) && mb_strlen($value) > (int) ResourceAPI::setting('filters.max_value_length')) {
            throw new FilterLimitExceededResourceAPIException('The maximum value length is ' . (int) ResourceAPI::setting('filters.max_value_length') . '.', $field);
        }

        $fail = static fn () => new IncorrectRequestParamsResourceAPIException("Invalid {$type} value for {$field}.", $field);

        return match ($type) {
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : throw $fail(),
            'float' => is_numeric($value) ? (float) $value : throw $fail(),
            'bool' => self::bool($value, $field),
            'datetime' => ($t = is_string($value) ? strtotime($value) : false) !== false ? date('Y-m-d H:i:s', $t) : throw $fail(),
            'date' => ($t = is_string($value) ? strtotime($value) : false) !== false ? date('Y-m-d', $t) : throw $fail(),
            default => (string) $value,
        };
    }

    private static function bool(mixed $value, string $field): bool
    {
        $parsed = is_bool($value) ? $value : match (strtolower((string) (is_scalar($value) ? $value : '?'))) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };

        return $parsed ?? throw new IncorrectRequestParamsResourceAPIException("Invalid boolean value for {$field}.", $field);
    }

    /**
     * Связь, через которую идёт запрос: у связанной модели обязан быть свой ресурс, а Policy
     * viewAny должна разрешать его просмотр, иначе фильтр, сортировка или include стали бы
     * обходом авторизации.
     *
     * @return array{0: ResourceAPI, 1: object}
     */
    private static function related(ResourceAPI $resource, string $relation, object $ctx, string $purpose): array
    {
        $definition = $resource->relations()[$relation] ?? null;
        $class = $definition?->related;
        $related = $class !== null ? $ctx->registry->forModel($class) : null;

        if ($definition === null || $related === null || ($ctx->authorizer !== null && !$ctx->authorizer->can('viewAny', $class))) {
            throw new RelationNotAllowedResourceAPIException('', $relation);
        }

        $supported = $definition instanceof BelongsTo || $definition instanceof HasOne
            || $definition instanceof HasMany || $definition instanceof BelongsToMany;

        if (!$supported && $purpose !== 'include') {
            throw new RelationNotAllowedResourceAPIException('', $relation);
        }

        return [$related, $definition];
    }

    // ------------------------------------------------------------------
    // sort
    // ------------------------------------------------------------------

    private static function sorts(ResourceAPI $resource, mixed $sort, object $ctx): array
    {
        if ($sort === null || $sort === '') {
            return [];
        }

        if (!is_string($sort)) {
            throw new IncorrectRequestParamsResourceAPIException('The sort parameter must be a string.', 'sort');
        }

        if (!$resource->sortEnabled()) {
            throw new IncorrectRequestParamsResourceAPIException('Sorting is disabled for this resource.', 'sort');
        }

        $result = [];

        foreach (explode(',', $sort) as $item) {
            [$path, $direction] = array_pad(explode(':', trim($item), 2), 2, 'asc');
            $direction = strtolower($direction);

            if ($path === '' || !in_array($direction, ['asc', 'desc'], true)) {
                throw new IncorrectRequestParamsResourceAPIException('Use sort=field:asc,other:desc.', 'sort');
            }

            $segments = explode('.', $path);
            $name = array_pop($segments);
            $current = $resource;
            $relations = [];

            foreach ($segments as $relation) {
                if (count($relations) + 1 > (int) ResourceAPI::setting('relation_deep')) {
                    throw new RelationDepthExceededResourceAPIException('', $path);
                }

                [$related, $definition] = self::related($current, $relation, $ctx, 'sort');

                if ($definition instanceof HasMany || $definition instanceof BelongsToMany) {
                    throw new SortByManyRelationResourceAPIException('', $path);
                }

                if (!$definition instanceof BelongsTo || !$related->sortEnabled()) {
                    throw new RelationNotAllowedResourceAPIException('', $relation);
                }

                $relations[] = $relation;
                $current = $related;
            }

            try {
                $column = $current->requireColumn($name, 'sort');
            } catch (ColumnNotFoundResourceAPIException) {
                throw new ColumnNotFoundResourceAPIException('', $path);
            }

            $result[$path] = ['relations' => $relations, 'column' => $column, 'direction' => $direction];
        }

        return array_values($result);
    }

    // ------------------------------------------------------------------
    // include / fields / search
    // ------------------------------------------------------------------

    /** @return list<string> */
    private static function includes(ResourceAPI $resource, mixed $include, object $ctx): array
    {
        if ($include === null || $include === '') {
            return [];
        }

        if (!is_string($include)) {
            throw new IncorrectRequestParamsResourceAPIException('The include parameter must be a string.', 'include');
        }

        $paths = [];

        foreach (array_filter(array_map('trim', explode(',', $include))) as $path) {
            $segments = explode('.', $path);
            $current = $resource;

            if (count($segments) > (int) ResourceAPI::setting('relation_deep')) {
                throw new RelationDepthExceededResourceAPIException('', $path);
            }

            foreach ($segments as $relation) {
                if (!in_array($relation, $current->includeList(), true)) {
                    throw new RelationNotAllowedResourceAPIException('', $path);
                }

                [$current] = self::related($current, $relation, $ctx, 'include');
            }

            $paths[$path] = $path;
        }

        return array_values($paths);
    }

    /** @return list<string>|null */
    private static function fields(ResourceAPI $resource, mixed $fields): ?array
    {
        if ($fields === null || $fields === '') {
            return null;
        }

        if (!is_string($fields)) {
            throw new IncorrectRequestParamsResourceAPIException('The fields parameter must be a string.', 'fields');
        }

        return array_values(array_unique(array_map(
            static fn (string $name) => $resource->requireColumn(trim($name), 'field'),
            array_filter(explode(',', $fields), static fn ($n) => trim($n) !== ''),
        )));
    }

    private static function search(ResourceAPI $resource, mixed $search): ?string
    {
        if ($search === null || $search === '') {
            return null;
        }

        if (!is_string($search)) {
            throw new IncorrectRequestParamsResourceAPIException('The search parameter must be a string.', 'search');
        }

        if ($resource->searchColumns() === []) {
            throw new IncorrectRequestParamsResourceAPIException('Search is not available for this resource.', 'search');
        }

        if (mb_strlen($search) > (int) ResourceAPI::setting('filters.max_value_length')) {
            throw new FilterLimitExceededResourceAPIException('The search string is too long.', 'search');
        }

        return $search;
    }
}
