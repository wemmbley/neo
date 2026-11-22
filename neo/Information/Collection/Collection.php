<?php

namespace Neo\Information\Collection;

use ArrayAccess;
use ArrayIterator;
use BadMethodCallException;
use Closure;
use Neo\Information\Collection\Exceptions\ItemNotFoundException;
use Neo\Information\Collection\Exceptions\MultipleItemsFoundException;
use Countable;
use Exception;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Stringable;
use Traversable;

/**
 * Standalone, Laravel-compatible collection.
 *
 * Merges Illuminate\Support\Collection and Illuminate\Database\Eloquent\Collection
 * into a single class. Eloquent-style behaviour (key based find/contains/diff/
 * intersect/only/except/unique/merge) kicks in automatically when every item is an
 * object exposing getKey() (i.e. an Eloquent model); otherwise the plain Laravel
 * base-collection behaviour is used.
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, Stringable
{
    /** @var array<array-key, mixed> */
    protected array $items = [];

    /** @var array<string, callable> */
    protected static array $macros = [];

    /** Methods available through higher-order proxies: $c->map->name */
    protected static array $proxies = [
        'average', 'avg', 'contains', 'doesntContain', 'each', 'every', 'filter', 'first',
        'flatMap', 'groupBy', 'keyBy', 'map', 'max', 'min', 'partition', 'reject', 'some',
        'sortBy', 'sortByDesc', 'sum', 'unique',
    ];

    public function __construct(mixed $items = [])
    {
        $this->items = static::getArrayableItems($items);
    }

    // ================================================================== factories

    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    /** Backward-compatible alias of make(). */
    public static function fromArr(array $items = []): static
    {
        return new static($items);
    }

    public static function wrap(mixed $value): static
    {
        if ($value instanceof self) {
            return new static($value);
        }

        return new static($value === null ? [] : (is_array($value) ? $value : [$value]));
    }

    public static function unwrap(mixed $value): mixed
    {
        return $value instanceof self ? $value->all() : $value;
    }

    public static function empty(): static
    {
        return new static([]);
    }

    public static function times(int $number, ?callable $callback = null): static
    {
        if ($number < 1) {
            return new static;
        }

        $collection = new static(range(1, $number));

        return $callback ? $collection->map($callback) : $collection;
    }

    public static function range(int|float $from, int|float $to, int|float $step = 1): static
    {
        return new static(range($from, $to, $step));
    }

    // ==================================================================== macros

    public static function macro(string $name, callable $macro): void
    {
        static::$macros[$name] = $macro;
    }

    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    public static function flushMacros(): void
    {
        static::$macros = [];
    }

    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo(null, static::class);
        }

        return $macro(...$parameters);
    }

    public function __call(string $method, array $parameters): mixed
    {
        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($this, static::class);
        }

        return $macro(...$parameters);
    }

    public function __get(string $key): HigherOrderCollectionProxy
    {
        if (! in_array($key, static::$proxies, true)) {
            throw new Exception("Property [{$key}] does not exist on this collection instance.");
        }

        return new HigherOrderCollectionProxy($this, $key);
    }

    // =================================================================== helpers

    protected static function getArrayableItems(mixed $items): array
    {
        if (is_array($items)) {
            return $items;
        }

        if ($items instanceof self) {
            return $items->all();
        }

        if ($items instanceof Traversable) {
            return iterator_to_array($items);
        }

        if ($items instanceof JsonSerializable) {
            return (array) $items->jsonSerialize();
        }

        if (is_object($items) && method_exists($items, 'toArray')) {
            return $items->toArray();
        }

        if ($items === null) {
            return [];
        }

        if (is_scalar($items)) {
            return [$items];
        }

        return (array) $items;
    }

    protected static function value(mixed $value, mixed ...$args): mixed
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }

    protected static function useAsCallable(mixed $value): bool
    {
        return ! is_string($value) && is_callable($value);
    }

    protected static function normalizeKey(mixed $key): mixed
    {
        return match (true) {
            is_bool($key) => (int) $key,
            $key === null => '',
            $key instanceof \BackedEnum => $key->value,
            $key instanceof \UnitEnum => $key->name,
            $key instanceof Stringable => (string) $key,
            default => $key,
        };
    }

    /** Laravel's data_get(): dot notation over arrays, ArrayAccess and objects. */
    protected static function dataGet(mixed $target, string|int|array|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', (string) $key);

        foreach ($key as $i => $segment) {
            unset($key[$i]);

            if ($segment === '*') {
                if ($target instanceof self) {
                    $target = $target->all();
                } elseif (! is_iterable($target)) {
                    return static::value($default);
                }

                $result = [];
                foreach ($target as $item) {
                    $result[] = static::dataGet($item, $key);
                }

                return in_array('*', $key, true)
                    ? array_merge([], ...array_map(fn ($r) => is_array($r) ? $r : [$r], $result))
                    : $result;
            }

            if ($target instanceof self) {
                $target = $target->all();
            }

            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif ($target instanceof ArrayAccess && isset($target[$segment])) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return static::value($default);
            }
        }

        return $target;
    }

    protected function valueRetriever(mixed $value): callable
    {
        if (static::useAsCallable($value)) {
            return $value;
        }

        return fn ($item) => static::dataGet($item, $value);
    }

    protected function operatorForWhere(mixed $key, mixed $operator = null, mixed $value = null): Closure
    {
        if (func_num_args() === 1) {
            $value = true;
            $operator = '=';
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return function ($item) use ($key, $operator, $value) {
            $retrieved = static::dataGet($item, $key);

            $strings = array_filter([$retrieved, $value], fn ($v) => is_string($v) || $v instanceof Stringable);

            if (count($strings) < 2 && count(array_filter([$retrieved, $value], 'is_object')) == 1) {
                return in_array($operator, ['!=', '<>', '!=='], true);
            }

            return match ($operator) {
                '!=', '<>' => $retrieved != $value,
                '<' => $retrieved < $value,
                '>' => $retrieved > $value,
                '<=' => $retrieved <= $value,
                '>=' => $retrieved >= $value,
                '===' => $retrieved === $value,
                '!==' => $retrieved !== $value,
                '<=>' => $retrieved <=> $value,
                default => $retrieved == $value,
            };
        };
    }

    // -------- Eloquent duck-typing helpers

    protected static function isKeyable(mixed $item): bool
    {
        return is_object($item) && method_exists($item, 'getKey');
    }

    protected static function allKeyable(array $items): bool
    {
        foreach ($items as $item) {
            if (! static::isKeyable($item)) {
                return false;
            }
        }

        return true;
    }

    /** True when the collection holds Eloquent-like models (non-empty, all have getKey()). */
    protected function isModelCollection(): bool
    {
        return $this->items !== [] && static::allKeyable($this->items);
    }

    /** Primary key of a model; 'id' for plain rows; a scalar is treated as the key itself. */
    protected static function keyOf(mixed $item): mixed
    {
        if (static::isKeyable($item)) {
            return $item->getKey();
        }

        if (! is_object($item) && ! is_array($item)) {
            return $item;
        }

        return static::dataGet($item, 'id');
    }

    protected static function dictKey(mixed $item): int|string
    {
        $key = static::keyOf($item);

        return is_int($key) || is_string($key) ? $key : (string) static::normalizeKey($key);
    }

    protected static function sameModel(object $a, object $b): bool
    {
        if (method_exists($a, 'is')) {
            return (bool) $a->is($b);
        }

        return $a::class === $b::class && static::keyOf($a) == static::keyOf($b);
    }

    protected function callOnItems(string $method, mixed ...$args): static
    {
        foreach ($this->items as $item) {
            if (is_object($item) && method_exists($item, $method)) {
                $item->{$method}(...$args);
            }
        }

        return $this;
    }

    // ================================================================ conversions

    public function all(): array
    {
        return $this->items;
    }

    public function toBase(): self
    {
        return new self($this);
    }

    public function toArray(): array
    {
        return array_map(
            fn ($value) => is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : $value,
            $this->all()
        );
    }

    public function jsonSerialize(): array
    {
        return array_map(function ($value) {
            if ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            }

            if (is_object($value) && method_exists($value, 'toJson')) {
                return json_decode($value->toJson(), true);
            }

            if (is_object($value) && method_exists($value, 'toArray')) {
                return $value->toArray();
            }

            return $value;
        }, $this->all());
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function containsOneItem(): bool
    {
        return $this->count() === 1;
    }

    // ================================================================= ArrayAccess

    public function offsetExists(mixed $key): bool
    {
        return isset($this->items[$key]);
    }

    public function offsetGet(mixed $key): mixed
    {
        return $this->items[$key];
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        if ($key === null) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    public function offsetUnset(mixed $key): void
    {
        unset($this->items[$key]);
    }

    // ================================================================= retrieving

    public function get(mixed $key, mixed $default = null): mixed
    {
        if (array_key_exists($key ?? '', $this->items)) {
            return $this->items[$key ?? ''];
        }

        return static::value($default);
    }

    public function has(mixed $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if (! array_key_exists($value, $this->items)) {
                return false;
            }
        }

        return true;
    }

    public function hasAny(mixed $key): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if (array_key_exists($value, $this->items)) {
                return true;
            }
        }

        return false;
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            foreach ($this->items as $item) {
                return $item;
            }

            return static::value($default);
        }

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return static::value($default);
    }

    public function firstWhere(mixed $key, mixed $operator = null, mixed $value = null): mixed
    {
        return $this->first($this->operatorForWhere(...func_get_args()));
    }

    public function firstOrFail(mixed $key = null, mixed $operator = null, mixed $value = null): mixed
    {
        $filter = func_num_args() > 1 ? $this->operatorForWhere(...func_get_args()) : $key;

        $placeholder = new \stdClass;
        $item = $this->first($filter, $placeholder);

        if ($item === $placeholder) {
            throw new ItemNotFoundException;
        }

        return $item;
    }

    public function sole(mixed $key = null, mixed $operator = null, mixed $value = null): mixed
    {
        $filter = func_num_args() > 1 ? $this->operatorForWhere(...func_get_args()) : $key;

        $items = $filter === null ? $this : $this->filter($filter);
        $count = $items->count();

        if ($count === 0) {
            throw new ItemNotFoundException;
        }

        if ($count > 1) {
            throw new MultipleItemsFoundException($count);
        }

        return $items->first();
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? static::value($default) : $this->items[array_key_last($this->items)];
        }

        foreach (array_reverse($this->items, true) as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return static::value($default);
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function pluck(mixed $value, mixed $key = null): static
    {
        $valueRetriever = $this->valueRetriever($value);
        $keyRetriever = $key === null ? null : $this->valueRetriever($key);

        $results = [];

        foreach ($this->items as $k => $item) {
            $itemValue = $valueRetriever($item, $k);

            if ($keyRetriever === null) {
                $results[] = $itemValue;
            } else {
                $results[static::normalizeKey($keyRetriever($item, $k))] = $itemValue;
            }
        }

        return new static($results);
    }

    public function random(?int $number = null): mixed
    {
        $count = $this->count();

        if ($number === null) {
            if ($count === 0) {
                throw new InvalidArgumentException('You requested 1 items, but there are only 0 items available.');
            }

            return $this->items[array_rand($this->items)];
        }

        if ($number > $count) {
            throw new InvalidArgumentException("You requested {$number} items, but there are only {$count} items available.");
        }

        if ($number === 0) {
            return new static;
        }

        $keys = (array) array_rand($this->items, $number);
        shuffle($keys);

        return new static(array_map(fn ($k) => $this->items[$k], $keys));
    }

    public function search(mixed $value, bool $strict = false): int|string|false
    {
        if (! static::useAsCallable($value)) {
            return array_search($value, $this->items, $strict);
        }

        foreach ($this->items as $key => $item) {
            if ($value($item, $key)) {
                return $key;
            }
        }

        return false;
    }

    // ================================================================== iteration

    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);

        if ($keys === []) {
            return new static;
        }

        return new static(array_combine($keys, array_map($callback, $this->items, $keys)));
    }

    public function transform(callable $callback): static
    {
        $this->items = $this->map($callback)->all();

        return $this;
    }

    public function mapWithKeys(callable $callback): static
    {
        $result = [];

        foreach ($this->items as $key => $value) {
            foreach ($callback($value, $key) as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }

        return new static($result);
    }

    public function mapInto(string $class): static
    {
        return $this->map(fn ($value, $key) => new $class($value, $key));
    }

    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->collapse();
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $result = $initial;

        foreach ($this->items as $key => $value) {
            $result = $callback($result, $value, $key);
        }

        return $result;
    }

    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    public function when(mixed $value = null, ?callable $callback = null, ?callable $default = null): mixed
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        if ($value) {
            return $callback ? ($callback($this, $value) ?? $this) : $this;
        }

        if ($default) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    public function unless(mixed $value = null, ?callable $callback = null, ?callable $default = null): mixed
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        return $this->when(! $value, $callback, $default);
    }

    public function whenEmpty(callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->isEmpty(), $callback, $default);
    }

    public function whenNotEmpty(callable $callback, ?callable $default = null): mixed
    {
        return $this->when($this->isNotEmpty(), $callback, $default);
    }

    public function dump(mixed ...$args): static
    {
        var_dump($this->all(), ...$args);

        return $this;
    }

    public function dd(mixed ...$args): never
    {
        $this->dump(...$args);

        exit(1);
    }

    // ================================================================== filtering

    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }

        return new static(array_filter($this->items));
    }

    public function reject(mixed $callback = true): static
    {
        $useAsCallable = static::useAsCallable($callback);

        return $this->filter(function ($value, $key) use ($callback, $useAsCallable) {
            return $useAsCallable ? ! $callback($value, $key) : $value != $callback;
        });
    }

    public function where(mixed $key, mixed $operator = null, mixed $value = null): static
    {
        return $this->filter($this->operatorForWhere(...func_get_args()));
    }

    public function whereStrict(string $key, mixed $value): static
    {
        return $this->where($key, '===', $value);
    }

    public function whereIn(string $key, mixed $values, bool $strict = false): static
    {
        $values = static::getArrayableItems($values);

        return $this->filter(fn ($item) => in_array(static::dataGet($item, $key), $values, $strict));
    }

    public function whereInStrict(string $key, mixed $values): static
    {
        return $this->whereIn($key, $values, true);
    }

    public function whereNotIn(string $key, mixed $values, bool $strict = false): static
    {
        $values = static::getArrayableItems($values);

        return $this->reject(fn ($item) => in_array(static::dataGet($item, $key), $values, $strict));
    }

    public function whereNull(?string $key = null): static
    {
        return $this->whereStrict((string) $key, null);
    }

    public function whereNotNull(?string $key = null): static
    {
        return $this->where($key, '!==', null);
    }

    public function whereBetween(string $key, mixed $values): static
    {
        $values = static::getArrayableItems($values);

        return $this->where($key, '>=', reset($values))->where($key, '<=', end($values));
    }

    public function whereNotBetween(string $key, mixed $values): static
    {
        $values = static::getArrayableItems($values);
        $min = reset($values);
        $max = end($values);

        return $this->filter(function ($item) use ($key, $min, $max) {
            $v = static::dataGet($item, $key);

            return $v < $min || $v > $max;
        });
    }

    public function whereInstanceOf(string|array $type): static
    {
        return $this->filter(function ($value) use ($type) {
            foreach ((array) $type as $class) {
                if ($value instanceof $class) {
                    return true;
                }
            }

            return false;
        });
    }

    public function partition(mixed $key, mixed $operator = null, mixed $value = null): static
    {
        $callback = func_num_args() === 1
            ? $this->valueRetriever($key)
            : $this->operatorForWhere(...func_get_args());

        $passed = [];
        $failed = [];

        foreach ($this->items as $k => $item) {
            if ($callback($item, $k)) {
                $passed[$k] = $item;
            } else {
                $failed[$k] = $item;
            }
        }

        return new static([new static($passed), new static($failed)]);
    }

    public function every(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            $callback = $this->valueRetriever($key);

            foreach ($this->items as $k => $v) {
                if (! $callback($v, $k)) {
                    return false;
                }
            }

            return true;
        }

        return $this->every($this->operatorForWhere(...func_get_args()));
    }

    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if (static::useAsCallable($key)) {
                foreach ($this->items as $k => $item) {
                    if ($key($item, $k)) {
                        return true;
                    }
                }

                return false;
            }

            // Eloquent: contains($model)
            if (static::isKeyable($key)) {
                foreach ($this->items as $item) {
                    if (static::isKeyable($item) && static::sameModel($item, $key)) {
                        return true;
                    }
                }

                return false;
            }

            // Eloquent: contains($primaryKey)
            if ($this->isModelCollection()) {
                foreach ($this->items as $item) {
                    if (static::keyOf($item) == $key) {
                        return true;
                    }
                }

                return false;
            }

            return in_array($key, $this->items);
        }

        return $this->contains($this->operatorForWhere(...func_get_args()));
    }

    public function containsStrict(mixed $key, mixed $value = null): bool
    {
        if (func_num_args() === 2) {
            return $this->contains(fn ($item) => static::dataGet($item, $key) === $value);
        }

        if (static::useAsCallable($key)) {
            return $this->first($key) !== null;
        }

        return in_array($key, $this->items, true);
    }

    public function doesntContain(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        return ! $this->contains(...func_get_args());
    }

    public function some(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        return $this->contains(...func_get_args());
    }

    public function unique(mixed $key = null, bool $strict = false): static
    {
        // Eloquent: unique models by primary key.
        if ($key === null && ! $strict && $this->isModelCollection()) {
            return new static(array_values($this->getDictionary()));
        }

        $callback = $this->valueRetriever($key);
        $exists = [];

        return $this->reject(function ($item, $k) use ($callback, $strict, &$exists) {
            if (in_array($id = $callback($item, $k), $exists, $strict)) {
                return true;
            }

            $exists[] = $id;

            return false;
        });
    }

    public function uniqueStrict(mixed $key = null): static
    {
        return $this->unique($key, true);
    }

    // ==================================================================== slicing

    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    public function forPage(int $page, int $perPage): static
    {
        $offset = max(0, ($page - 1) * $perPage);

        return $this->slice($offset, $perPage);
    }

    public function nth(int $step, int $offset = 0): static
    {
        $new = [];
        $position = 0;

        foreach ($this->slice($offset)->items as $item) {
            if ($position % $step === 0) {
                $new[] = $item;
            }

            $position++;
        }

        return new static($new);
    }

    public function chunk(int $size, bool $preserveKeys = true): static
    {
        if ($size <= 0) {
            return new static;
        }

        $chunks = [];

        foreach (array_chunk($this->items, $size, $preserveKeys) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    public function splice(int $offset, ?int $length = null, mixed $replacement = []): static
    {
        if (func_num_args() === 1) {
            return new static(array_splice($this->items, $offset));
        }

        return new static(array_splice($this->items, $offset, $length, static::getArrayableItems($replacement)));
    }

    // ================================================================== mutation

    public function add(mixed $item): static
    {
        $this->items[] = $item;

        return $this;
    }

    public function push(mixed ...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    public function put(mixed $key, mixed $value): static
    {
        $this->offsetSet($key, $value);

        return $this;
    }

    public function prepend(mixed $value, mixed $key = null): static
    {
        if (func_num_args() === 1) {
            array_unshift($this->items, $value);
        } else {
            $this->items = [$key => $value] + $this->items;
        }

        return $this;
    }

    public function pop(int $count = 1): mixed
    {
        if ($count === 1) {
            return array_pop($this->items);
        }

        if ($this->isEmpty()) {
            return new static;
        }

        $results = [];

        foreach (range(1, min($count, $this->count())) as $ignored) {
            $results[] = array_pop($this->items);
        }

        return new static($results);
    }

    public function shift(int $count = 1): mixed
    {
        if ($count === 1) {
            return array_shift($this->items);
        }

        if ($this->isEmpty()) {
            return new static;
        }

        $results = [];

        foreach (range(1, min($count, $this->count())) as $ignored) {
            $results[] = array_shift($this->items);
        }

        return new static($results);
    }

    public function pull(mixed $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);

        $this->forget($key);

        return $value;
    }

    public function forget(mixed $keys): static
    {
        foreach ((array) $keys as $key) {
            $this->offsetUnset($key);
        }

        return $this;
    }

    // ================================================================== combining

    public function merge(mixed $items): static
    {
        $other = static::getArrayableItems($items);

        // Eloquent: models are merged by primary key.
        if ($this->isModelCollection() && static::allKeyable($other)) {
            $dictionary = $this->getDictionary();

            foreach ($other as $item) {
                $dictionary[static::dictKey($item)] = $item;
            }

            return new static(array_values($dictionary));
        }

        return new static(array_merge($this->items, $other));
    }

    public function concat(iterable $source): static
    {
        $result = new static($this);

        foreach ($source as $item) {
            $result->push($item);
        }

        return $result;
    }

    public function combine(mixed $values): static
    {
        return new static(array_combine($this->all(), static::getArrayableItems($values)));
    }

    public function zip(mixed ...$items): static
    {
        $arrayableItems = array_map(fn ($i) => static::getArrayableItems($i), $items);

        $params = array_merge([fn () => new static(func_get_args()), $this->items], $arrayableItems);

        return new static(array_map(...$params));
    }

    public function pad(int $size, mixed $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    public function collapse(): static
    {
        $results = [];

        foreach ($this->items as $values) {
            if ($values instanceof self) {
                $values = $values->all();
            } elseif (! is_array($values)) {
                continue;
            }

            $results[] = $values;
        }

        return new static(array_merge([], ...$results));
    }

    public function flatten(int|float $depth = INF): static
    {
        return new static(static::flattenArray($this->items, $depth));
    }

    protected static function flattenArray(iterable $array, int|float $depth): array
    {
        $result = [];

        foreach ($array as $item) {
            $item = $item instanceof self ? $item->all() : $item;

            if (! is_array($item)) {
                $result[] = $item;
            } else {
                $values = $depth === 1 ? array_values($item) : static::flattenArray($item, $depth - 1);

                foreach ($values as $value) {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);

        return new static($items);
    }

    // ====================================================== diff / intersect / only

    public function diff(mixed $items): static
    {
        $other = static::getArrayableItems($items);

        // Eloquent: compare by primary key.
        if ($this->isModelCollection()) {
            $dictionary = $this->getDictionary($other);

            return $this->filter(fn ($item) => ! isset($dictionary[static::dictKey($item)]))->values();
        }

        return new static(array_diff($this->items, $other));
    }

    public function diffKeys(mixed $items): static
    {
        return new static(array_diff_key($this->items, static::getArrayableItems($items)));
    }

    public function diffAssoc(mixed $items): static
    {
        return new static(array_diff_assoc($this->items, static::getArrayableItems($items)));
    }

    public function intersect(mixed $items): static
    {
        $other = static::getArrayableItems($items);

        if ($this->isModelCollection()) {
            $dictionary = $this->getDictionary($other);

            return $this->filter(fn ($item) => isset($dictionary[static::dictKey($item)]))->values();
        }

        return new static(array_intersect($this->items, $other));
    }

    public function intersectByKeys(mixed $items): static
    {
        return new static(array_intersect_key($this->items, static::getArrayableItems($items)));
    }

    public function only(mixed $keys): static
    {
        if ($keys === null) {
            return new static($this->items);
        }

        $keys = $keys instanceof self ? $keys->all() : (is_array($keys) ? $keys : func_get_args());

        if ($this->isModelCollection()) {
            $wanted = array_flip(array_map(fn ($k) => static::dictKey($k), $keys));

            return $this->filter(fn ($item) => isset($wanted[static::dictKey($item)]))->values();
        }

        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    public function except(mixed $keys): static
    {
        if ($keys === null) {
            return new static($this->items);
        }

        $keys = $keys instanceof self ? $keys->all() : (is_array($keys) ? $keys : func_get_args());

        if ($this->isModelCollection()) {
            $unwanted = array_flip(array_map(fn ($k) => static::dictKey($k), $keys));

            return $this->reject(fn ($item) => isset($unwanted[static::dictKey($item)]))->values();
        }

        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    // ================================================================== sorting

    public function sort(mixed $callback = null): static
    {
        $items = $this->items;

        if ($callback && is_callable($callback)) {
            uasort($items, $callback);
        } else {
            asort($items, $callback ?? SORT_REGULAR);
        }

        return new static($items);
    }

    public function sortDesc(int $options = SORT_REGULAR): static
    {
        $items = $this->items;

        arsort($items, $options);

        return new static($items);
    }

    public function sortBy(mixed $callback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        if (is_array($callback) && ! is_callable($callback)) {
            return $this->sortByMany($callback);
        }

        $callback = $this->valueRetriever($callback);

        $results = [];

        foreach ($this->items as $key => $value) {
            $results[$key] = $callback($value, $key);
        }

        $descending ? arsort($results, $options) : asort($results, $options);

        foreach (array_keys($results) as $key) {
            $results[$key] = $this->items[$key];
        }

        return new static($results);
    }

    public function sortByDesc(mixed $callback, int $options = SORT_REGULAR): static
    {
        return $this->sortBy($callback, $options, true);
    }

    protected function sortByMany(array $comparisons): static
    {
        $items = $this->items;

        uasort($items, function ($a, $b) use ($comparisons) {
            foreach ($comparisons as $comparison) {
                $comparison = is_array($comparison) ? $comparison : [$comparison];
                $prop = $comparison[0];
                $direction = $comparison[1] ?? 'asc';
                $ascending = is_string($direction) ? strtolower($direction) !== 'desc' : (bool) $direction;

                if ($prop instanceof Closure) {
                    $result = $prop($a, $b);
                } else {
                    $values = [static::dataGet($a, $prop), static::dataGet($b, $prop)];

                    if (! $ascending) {
                        $values = array_reverse($values);
                    }

                    $result = $values[0] <=> $values[1];
                }

                if ($result !== 0) {
                    return $result;
                }
            }

            return 0;
        });

        return new static($items);
    }

    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;

        $descending ? krsort($items, $options) : ksort($items, $options);

        return new static($items);
    }

    public function sortKeysDesc(int $options = SORT_REGULAR): static
    {
        return $this->sortKeys($options, true);
    }

    // ================================================================= grouping

    public function groupBy(mixed $groupBy, bool $preserveKeys = false): static
    {
        $nextGroups = [];

        if (! static::useAsCallable($groupBy) && is_array($groupBy)) {
            $nextGroups = $groupBy;
            $groupBy = array_shift($nextGroups);
        }

        $groupBy = $this->valueRetriever($groupBy);

        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKeys = $groupBy($value, $key);

            if (! is_array($groupKeys)) {
                $groupKeys = [$groupKeys];
            }

            foreach ($groupKeys as $groupKey) {
                $groupKey = static::normalizeKey($groupKey);

                if (! array_key_exists($groupKey, $results)) {
                    $results[$groupKey] = new static;
                }

                $results[$groupKey]->offsetSet($preserveKeys ? $key : null, $value);
            }
        }

        $result = new static($results);

        if (! empty($nextGroups)) {
            return $result->map(fn ($group) => $group->groupBy($nextGroups, $preserveKeys));
        }

        return $result;
    }

    public function keyBy(mixed $keyBy): static
    {
        $keyBy = $this->valueRetriever($keyBy);

        $results = [];

        foreach ($this->items as $key => $item) {
            $results[static::normalizeKey($keyBy($item, $key))] = $item;
        }

        return new static($results);
    }

    public function countBy(mixed $countBy = null): static
    {
        $countBy = $countBy === null ? fn ($v) => $v : $this->valueRetriever($countBy);

        $counts = [];

        foreach ($this->items as $key => $value) {
            $group = static::normalizeKey($countBy($value, $key));

            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }

        return new static($counts);
    }

    // ================================================================ aggregates

    public function sum(mixed $callback = null): mixed
    {
        $callback = $this->valueRetriever($callback);

        return $this->reduce(fn ($result, $item) => $result + $callback($item), 0);
    }

    public function avg(mixed $callback = null): mixed
    {
        $callback = $this->valueRetriever($callback);

        $items = $this->map(fn ($value) => $callback($value))->filter(fn ($value) => ! is_null($value));

        if ($count = $items->count()) {
            return $items->sum() / $count;
        }

        return null;
    }

    public function average(mixed $callback = null): mixed
    {
        return $this->avg($callback);
    }

    public function min(mixed $callback = null): mixed
    {
        $callback = $this->valueRetriever($callback);

        return $this->map(fn ($value) => $callback($value))
            ->filter(fn ($value) => ! is_null($value))
            ->reduce(fn ($result, $value) => is_null($result) || $value < $result ? $value : $result);
    }

    public function max(mixed $callback = null): mixed
    {
        $callback = $this->valueRetriever($callback);

        return $this->map(fn ($value) => $callback($value))
            ->filter(fn ($value) => ! is_null($value))
            ->reduce(fn ($result, $value) => is_null($result) || $value > $result ? $value : $result);
    }

    public function implode(mixed $value, ?string $glue = null): string
    {
        $first = $this->first();

        if (is_array($first) || (is_object($first) && ! $first instanceof Stringable)) {
            return implode($glue ?? '', $this->pluck($value)->all());
        }

        return implode($value ?? '', $this->items);
    }

    public function join(string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '') {
            return $this->implode($glue);
        }

        $count = $this->count();

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return (string) $this->last();
        }

        $collection = new static($this->items);
        $finalItem = $collection->pop();

        return $collection->implode($glue).$finalGlue.$finalItem;
    }

    // ================================================================ pagination

    /**
     * Paginate the collection.
     *
     * Options: path, query, fragment, pageName, onEachSide.
     */
    public function paginate(?int $perPage = 15, ?int $page = null, array $options = []): LengthAwarePaginator
    {
        $perPage = $perPage ?: 15;
        $pageName = $options['pageName'] ?? 'page';
        $page = $page ?: AbstractPaginator::resolveCurrentPage($pageName);
        $options['path'] ??= AbstractPaginator::resolveCurrentPath();

        return new LengthAwarePaginator(
            $this->forPage($page, $perPage)->values(),
            $this->count(),
            $perPage,
            $page,
            $options
        );
    }

    public function simplePaginate(?int $perPage = 15, ?int $page = null, array $options = []): Paginator
    {
        $perPage = $perPage ?: 15;
        $pageName = $options['pageName'] ?? 'page';
        $page = $page ?: AbstractPaginator::resolveCurrentPage($pageName);
        $options['path'] ??= AbstractPaginator::resolveCurrentPath();

        // perPage + 1 so the paginator can detect whether a next page exists.
        return new Paginator(
            $this->slice(max(0, ($page - 1) * $perPage), $perPage + 1)->values(),
            $perPage,
            $page,
            $options
        );
    }

    // ============================================================ Eloquent-style

    /** Dictionary of items keyed by primary key. */
    public function getDictionary(mixed $items = null): array
    {
        $items = $items === null ? $this->items : static::getArrayableItems($items);

        $dictionary = [];

        foreach ($items as $value) {
            $dictionary[static::dictKey($value)] = $value;
        }

        return $dictionary;
    }

    /** Eloquent Collection::modelKeys(). */
    public function modelKeys(): array
    {
        return array_map(fn ($model) => static::keyOf($model), $this->items);
    }

    /**
     * Eloquent Collection::find().
     * Accepts a key, a model, or an array/collection of keys (returns a collection then).
     */
    public function find(mixed $key, mixed $default = null): mixed
    {
        if (static::isKeyable($key)) {
            $key = $key->getKey();
        }

        if ($key instanceof self) {
            $key = $key->all();
        }

        if (is_array($key)) {
            if ($this->isEmpty()) {
                return new static;
            }

            $wanted = array_flip(array_map(fn ($k) => static::dictKey($k), $key));

            return $this->filter(fn ($model) => isset($wanted[static::dictKey($model)]))->values();
        }

        return $this->first(fn ($model) => static::keyOf($model) == $key, $default);
    }

    public function makeVisible(mixed $attributes): static
    {
        return $this->callOnItems('makeVisible', $attributes);
    }

    public function makeHidden(mixed $attributes): static
    {
        return $this->callOnItems('makeHidden', $attributes);
    }

    public function setVisible(array $visible): static
    {
        return $this->callOnItems('setVisible', $visible);
    }

    public function setHidden(array $hidden): static
    {
        return $this->callOnItems('setHidden', $hidden);
    }

    public function append(mixed $attributes): static
    {
        return $this->callOnItems('append', $attributes);
    }

    /** Eloquent Collection::load() — eager loads relations for all models in one go. */
    public function load(mixed $relations): static
    {
        if ($this->isNotEmpty()) {
            if (is_string($relations)) {
                $relations = func_get_args();
            }

            $query = $this->first()->newQueryWithoutRelationships()->with($relations);

            $this->items = $query->eagerLoadRelations($this->items);
        }

        return $this;
    }

    /** Eloquent Collection::fresh() — reload models from the database. */
    public function fresh(mixed $with = []): static
    {
        if ($this->isEmpty()) {
            return new static;
        }

        $with = is_string($with) ? func_get_args() : $with;

        $model = $this->first();

        $query = $model->newQueryWithoutScopes()
            ->with($with)
            ->whereIn($model->getKeyName(), $this->modelKeys());

        $fresh = [];

        foreach ($query->get() as $freshModel) {
            $fresh[$freshModel->getKey()] = $freshModel;
        }

        return $this
            ->filter(fn ($m) => $m->exists && isset($fresh[$m->getKey()]))
            ->map(fn ($m) => $fresh[$m->getKey()]);
    }

    /** Eloquent Collection::toQuery(). */
    public function toQuery(): mixed
    {
        $model = $this->first();

        if (! $model) {
            throw new LogicException('Unable to create query for empty collection.');
        }

        $class = get_class($model);

        if ($this->filter(fn ($m) => ! $m instanceof $class)->isNotEmpty()) {
            throw new LogicException('Unable to create query for collection with mixed types.');
        }

        return $model->newModelQuery()->whereKey($this->modelKeys());
    }
}
