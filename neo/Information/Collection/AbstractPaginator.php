<?php

namespace Neo\Information\Collection;

use ArrayAccess;
use BadMethodCallException;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Traversable;

abstract class AbstractPaginator implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, Stringable
{
    protected Collection $items;
    protected int $perPage = 15;
    protected int $currentPage = 1;
    protected string $path = '/';
    protected array $query = [];
    protected ?string $fragment = null;
    protected string $pageName = 'page';
    protected int $onEachSide = 3;
    protected array $options = [];

    protected static ?Closure $currentPathResolver = null;
    protected static ?Closure $currentPageResolver = null;
    protected static ?Closure $queryStringResolver = null;

    // ---------------------------------------------------------------- resolvers

    public static function resolveCurrentPath(string $default = '/'): string
    {
        if (static::$currentPathResolver) {
            return (string) (static::$currentPathResolver)();
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $default;
    }

    public static function currentPathResolver(Closure $resolver): void
    {
        static::$currentPathResolver = $resolver;
    }

    /**
     * Номер текущей страницы из запроса. Основной формат `?page[number]=2&page[size]=15`
     * (как в ResourceAPI); для совместимости понимается и плоский `?page=2`.
     */
    public static function resolveCurrentPage(string $pageName = 'page', int $default = 1): int
    {
        $page = static::pageParameter($pageName);

        if (is_array($page)) {
            $page = $page['number'] ?? null;
        }

        if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
            return (int) $page;
        }

        return $default;
    }

    /**
     * Размер страницы из запроса (`?page[size]=15`). Не задан или некорректен: $default.
     * Верхнюю границу контролирует тот, кто принимает запрос (ResourceAPI: pagination.max_per_page).
     */
    public static function resolvePerPage(string $pageName = 'page', int $default = 15): int
    {
        $page = static::pageParameter($pageName);
        $size = is_array($page) ? ($page['size'] ?? null) : null;

        if (filter_var($size, FILTER_VALIDATE_INT) !== false && (int) $size >= 1) {
            return (int) $size;
        }

        return $default;
    }

    protected static function pageParameter(string $pageName): mixed
    {
        return static::$currentPageResolver
            ? (static::$currentPageResolver)($pageName)
            : ($_GET[$pageName] ?? null);
    }

    public static function currentPageResolver(Closure $resolver): void
    {
        static::$currentPageResolver = $resolver;
    }

    public static function resolveQueryString(): array
    {
        if (static::$queryStringResolver) {
            return (array) (static::$queryStringResolver)();
        }

        return $_GET;
    }

    public static function queryStringResolver(Closure $resolver): void
    {
        static::$queryStringResolver = $resolver;
    }

    // ------------------------------------------------------------------ options

    protected function setOptions(array $options): void
    {
        $this->options = $options;

        foreach (['path', 'query', 'fragment', 'pageName', 'onEachSide'] as $key) {
            if (array_key_exists($key, $options)) {
                $this->{$key} = $options[$key];
            }
        }

        $this->path = $this->path !== '/' ? rtrim($this->path, '/') : $this->path;
    }

    protected function isValidPageNumber(mixed $page): bool
    {
        return $page >= 1 && filter_var($page, FILTER_VALIDATE_INT) !== false;
    }

    protected function setCurrentPage(mixed $currentPage): int
    {
        $currentPage = $currentPage ?: static::resolveCurrentPage($this->pageName);

        return $this->isValidPageNumber($currentPage) ? (int) $currentPage : 1;
    }

    // --------------------------------------------------------------------- urls

    public function url(int $page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        $parameters = [$this->pageName => ['number' => $page, 'size' => $this->perPage]];

        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        # Скобки оставляем читаемыми: page[number]=2, а не page%5Bnumber%5D=2.
        $query = str_replace(
            ['%5B', '%5D'],
            ['[', ']'],
            http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
        );

        return $this->path
            .(str_contains($this->path, '?') ? '&' : '?')
            .$query
            .$this->buildFragment();
    }

    public function getUrlRange(int $start, int $end): array
    {
        $urls = [];

        for ($page = $start; $page <= $end; $page++) {
            $urls[$page] = $this->url($page);
        }

        return $urls;
    }

    public function previousPageUrl(): ?string
    {
        return $this->currentPage > 1 ? $this->url($this->currentPage - 1) : null;
    }

    abstract public function nextPageUrl(): ?string;

    abstract public function hasMorePages(): bool;

    public function appends(array|string|null $key, mixed $value = null): static
    {
        if ($key === null) {
            return $this;
        }

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->addQuery($k, $v);
            }

            return $this;
        }

        $this->addQuery($key, $value);

        return $this;
    }

    protected function addQuery(string $key, mixed $value): void
    {
        if ($key !== $this->pageName) {
            $this->query[$key] = $value;
        }
    }

    public function withQueryString(): static
    {
        return $this->appends(static::resolveQueryString());
    }

    public function fragment(?string $fragment = null): static|string|null
    {
        if ($fragment === null) {
            return $this->fragment;
        }

        $this->fragment = $fragment;

        return $this;
    }

    protected function buildFragment(): string
    {
        return $this->fragment ? '#'.$this->fragment : '';
    }

    public function setPath(string $path): static
    {
        $this->path = $path !== '/' ? rtrim($path, '/') : $path;

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function getPageName(): string
    {
        return $this->pageName;
    }

    public function setPageName(string $name): static
    {
        $this->pageName = $name;

        return $this;
    }

    public function onEachSide(int $count): static
    {
        $this->onEachSide = $count;

        return $this;
    }

    // -------------------------------------------------------------------- items

    public function items(): array
    {
        return $this->items->all();
    }

    public function getCollection(): Collection
    {
        return $this->items;
    }

    public function setCollection(Collection $collection): static
    {
        $this->items = $collection;

        return $this;
    }

    public function through(callable $callback): static
    {
        $this->items->transform($callback);

        return $this;
    }

    public function firstItem(): ?int
    {
        return count($this->items) > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null;
    }

    public function lastItem(): ?int
    {
        return count($this->items) > 0 ? $this->firstItem() + $this->items->count() - 1 : null;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function hasPages(): bool
    {
        return $this->currentPage() != 1 || $this->hasMorePages();
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function isNotEmpty(): bool
    {
        return $this->items->isNotEmpty();
    }

    // -------------------------------------------------------------- conversions

    /** Ссылки пагинатора: first, prev, next (и last, если известно общее число страниц). */
    abstract public function links(): array;

    /** Счётчики страниц: current_page, per_page (и last_page, total, если известны). */
    abstract public function pages(): array;

    /** Диапазон записей текущей страницы. */
    public function rows(): array
    {
        return ['from' => $this->firstItem(), 'to' => $this->lastItem()];
    }

    /** Блок `paginator` ответа: `links` + `meta` (path, rows, pages). */
    public function paginator(): array
    {
        return [
            'links' => $this->links(),
            'meta' => [
                'path' => $this->path(),
                'rows' => $this->rows(),
                'pages' => $this->pages(),
            ],
        ];
    }

    /**
     * Единый формат (совпадает с ResourceAPI): записи в `data`, всё остальное в `paginator`.
     *
     *   { "data": [...], "paginator": { "links": {...}, "meta": { "path", "rows", "pages" } } }
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'paginator' => $this->paginator(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function getIterator(): Traversable
    {
        return $this->items->getIterator();
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function offsetExists(mixed $key): bool
    {
        return $this->items->has($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        return $this->items->get($key);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->items->put($key, $value);
    }

    public function offsetUnset(mixed $key): void
    {
        $this->items->forget($key);
    }

    public function __call(string $method, array $parameters): mixed
    {
        if (! method_exists($this->items, $method) && ! Collection::hasMacro($method)) {
            throw new BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
        }

        return $this->items->{$method}(...$parameters);
    }
}
