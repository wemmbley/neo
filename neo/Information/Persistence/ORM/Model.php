<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM;

use BackedEnum;
use Closure;
use DateTimeInterface;
use JsonSerializable;
use LogicException;
use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\ORM\Casts\Caster;
use Neo\Information\Persistence\ORM\Casts\DateCaster;
use Neo\Foundation\Kernel\Events\Dispatcher;
use Neo\Information\Persistence\ORM\Events\WatchEventFactory;
use Neo\Information\Persistence\ORM\Exceptions\ImmutableAttributeException;
use Neo\Information\Persistence\ORM\Exceptions\LazyLoadingViolationException;
use Neo\Information\Persistence\ORM\Factories\Factory;
use Neo\Information\Persistence\ORM\Factories\FactoryManager;
use Neo\Information\Persistence\ORM\Metadata\ModelMetadata;
use Neo\Information\Persistence\ORM\Relations\Relation;
use Neo\Information\Persistence\ORM\Relations\RelationFactory;
use Neo\Information\Validator\Validator;
use Stringable;

/**
 * Базовая модель.
 *
 * Два способа описать поля, их можно смешивать:
 *  1. Атрибуты на публичных типизированных свойствах (#[PrimaryKey], #[Fillable], #[Cast], #[Column] ...).
 *  2. Классические защищённые массивы ($fillable, $guarded, $hidden, $casts, $table ...);
 *     неописанные колонки живут как динамические атрибуты ($model->title).
 *
 * @method static Builder<static> with(string|array ...$relations)
 * @method static Builder<static> where(string|\Closure|array $column, mixed $operator = null, mixed $value = null)
 * @method static mixed paginate(int $perPage = 15, ?int $page = null, array $options = [], string $pageName = 'page')
 * @method static mixed simplePaginate(int $perPage = 15, ?int $page = null, array $options = [], string $pageName = 'page')
 * @method static Builder<static> orWhere(string|\Closure|array $column, mixed $operator = null, mixed $value = null)
 * @method static Builder<static> whereIn(string $column, array $values)
 * @method static Builder<static> whereNull(string $column)
 * @method static Builder<static> whereNotNull(string $column)
 * @method static Builder<static> whereBetween(string $column, array $values)
 * @method static Builder<static> orderBy(string $column, string $direction = 'asc')
 * @method static Builder<static> latest(string $column = 'created_at')
 * @method static Builder<static> oldest(string $column = 'created_at')
 * @method static Builder<static> limit(int $value)
 * @method static Builder<static> offset(int $value)
 * @method static Builder<static> select(string ...$columns)
 * @method static Builder<static> join(string $table, string $first, string $operator, string $second)
 * @method static Builder<static> groupBy(string ...$columns)
 * @method static static|null first()
 * @method static static firstOrFail()
 * @method static static firstOrCreate(array $attributes, array $values = [])
 * @method static static updateOrCreate(array $attributes, array $values = [])
 * @method static int count(string $column = '*')
 * @method static bool exists()
 * @method static int|float sum(string $column)
 * @method static mixed max(string $column)
 * @method static mixed min(string $column)
 * @method static array pluck(string $column, ?string $key = null)
 * @method static int delete()
 */
abstract class Model implements JsonSerializable, Stringable
{
    /** События жизненного цикла. Имя в диспетчере: `orm.created: App\\Models\\Product`. */
    public const EVENTS = ['saving', 'saved', 'creating', 'created', 'updating', 'updated', 'deleting', 'deleted'];

    protected ?string $table = null;
    protected ?string $connection = null;
    protected string $primaryKey = 'id';
    protected bool $incrementing = true;
    protected bool $timestamps = true;

    /** @var list<string> */
    protected array $fillable = [];
    /** @var list<string> */
    protected array $guarded = [];
    /** @var list<string> */
    protected array $hidden = [];
    /** @var array<string, string> */
    protected array $casts = [];

    /** @var list<string> отношения, которые грузятся вместе с моделью всегда */
    protected array $with = [];

    /** Сохранена ли модель в БД. */
    public bool $exists = false;

    /** @var array<string, mixed> динамические атрибуты (колонки без объявленного свойства) */
    private array $attributes = [];

    /** @var array<string, mixed> снимок значений в формате БД после загрузки/сохранения */
    private array $original = [];

    /** @var array<string, mixed> загруженные отношения */
    private array $relationCache = [];

    private static ?DatabaseManager $resolver = null;
    private static ?Dispatcher $events = null;
    private static string $collectionClass = 'Neo\\Information\\Collection\\Collection';

    /** @var array<class-string, true> классы, чьи наблюдатели из #[ObservedBy] уже подключены */
    private static array $booted = [];
    private static bool $preventLazyLoading = false;
    private static ?Closure $lazyLoadingHandler = null;

    /** Модель получена из запроса (а не создана в коде): только для неё работает запрет ленивой загрузки. */
    private bool $fromQuery = false;
    private static ?FactoryManager $factories = null;

    # --------------------------------------------------------------- wiring

    public static function setConnectionResolver(?DatabaseManager $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function setEventDispatcher(?Dispatcher $events): void
    {
        self::$events = $events;
        self::$booted = [];
    }

    /**
     * Подписаться на событие модели: `Product::listen('created', fn (Product $p) => ...)`.
     * "-ing" события можно отменить, вернув false.
     */
    public static function listen(string $event, \Closure|array|string $listener): void
    {
        (self::$events ?? throw new LogicException('EventDispatcher не подключён (ORMServiceProvider).'))
            ->listen('orm.' . $event . ': ' . static::class, $listener);
    }

    /** Подключить наблюдателя: методы с именами событий получают модель. */
    public static function observe(string $observer): void
    {
        foreach (self::EVENTS as $event) {
            if (method_exists($observer, $event)) {
                static::listen($event, [$observer, $event]);
            }
        }
    }

    private static function bootObservers(): void
    {
        if (self::$events === null || isset(self::$booted[static::class])) {
            return;
        }

        self::$booted[static::class] = true;

        foreach (static::metadata()->observers as $observer) {
            static::observe($observer);
        }
    }

    private function fireModelEvent(string $event, bool $halt = false): mixed
    {
        if (self::$events === null) {
            return null;
        }

        $name = 'orm.' . $event . ': ' . static::class;

        return $halt ? self::$events->until($name, [$this]) : self::$events->dispatch($name, [$this]);
    }

    /** Класс коллекции для результатов: должен иметь статический make(array). */
    public static function useCollection(string $class): void
    {
        self::$collectionClass = $class;
    }

    /**
     * Запретить ленивую загрузку отношений у моделей, полученных из запроса (защита от N+1).
     * Нарушение бросает LazyLoadingViolationException; своё поведение: handleLazyLoadingViolationUsing().
     */
    public static function preventLazyLoading(bool $value = true): void
    {
        self::$preventLazyLoading = $value;
    }

    /** @param Closure(Model, string): void|null $handler вместо исключения (например, запись в лог) */
    public static function handleLazyLoadingViolationUsing(?Closure $handler): void
    {
        self::$lazyLoadingHandler = $handler;
    }

    public static function setFactoryManager(?FactoryManager $factories): void
    {
        self::$factories = $factories;
    }

    public static function metadata(): ModelMetadata
    {
        return ModelMetadata::for(static::class);
    }

    public function getConnection(): Connection
    {
        $resolver = self::$resolver ?? throw new LogicException(
            'ORM не инициализирован: зарегистрируйте ORMServiceProvider или вызовите Model::setConnectionResolver().'
        );

        return $resolver->connection(static::metadata()->connection);
    }

    # ---------------------------------------------------------- construction

    public function __construct(array $attributes = [])
    {
        static::bootObservers();

        $vars = get_object_vars($this);

        foreach (static::metadata()->fields as $prop => $field) {
            if ($field->hasDefault && !($field->default === null && !$field->nullable)) {
                $this->{$prop} = $field->default;
            }
        }

        if ($attributes !== []) {
            $this->fill($attributes);
        }
    }

    /** @param list<static> $models */
    public function newCollection(array $models = []): mixed
    {
        return (self::$collectionClass)::make($models);
    }

    /** @return list<string> */
    public function getWith(): array
    {
        return $this->with;
    }

    public function newInstance(array $attributes = []): static
    {
        return new static($attributes);
    }

    /** Модель из строки БД (exists = true). */
    public function newFromRow(array $row): static
    {
        return (new static())->hydrateRow($row);
    }

    protected function hydrateRow(array $row): static
    {
        $meta = static::metadata();

        foreach ($row as $column => $value) {
            $field = $meta->fieldByColumn((string) $column);

            if ($field === null) {
                $this->attributes[$column] = Caster::fromDb($value, $meta->classicCasts[$column] ?? null);
            } elseif ($value !== null || $field->nullable) {
                $this->{$field->property} = Caster::fromDb($value, $field->cast);
            }
        }

        $this->exists = true;
        $this->fromQuery = true;
        $this->relationCache = [];
        $this->syncOriginal();

        return $this;
    }

    # ------------------------------------------------------------ attributes

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable((string) $key)) {
                $this->setAttribute((string) $key, $value);
            }
        }

        return $this;
    }

    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }

        return $this;
    }

    public function isFillable(string $key): bool
    {
        $meta = static::metadata();
        $prop = $this->resolveProperty($key);

        if ($prop !== null) {
            return $meta->fields[$prop]->fillable;
        }

        if ($this->fillable !== []) {
            return in_array($key, $this->fillable, true);
        }

        return $this->guarded === [] || ($this->guarded !== ['*'] && !in_array($key, $this->guarded, true));
    }

    private function resolveProperty(string $key): ?string
    {
        $meta = static::metadata();

        return isset($meta->fields[$key]) ? $key : ($meta->columns[$key] ?? null);
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $meta = static::metadata();
        $prop = $this->resolveProperty($key);

        if ($prop === null) {
            $this->attributes[$key] = Caster::fromDb($value, $meta->classicCasts[$key] ?? null);

            return $this;
        }

        $field = $meta->fields[$prop];

        if ($value === null && !$field->nullable) {
            throw new \TypeError(static::class . '::$' . $prop . ' не принимает null.');
        }

        $this->{$prop} = Caster::fromDb($value, $field->cast);

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        $prop = $this->resolveProperty($key);

        if ($prop !== null) {
            $vars = get_object_vars($this);

            return array_key_exists($prop, $vars) ? $vars[$prop] : null;
        }

        return $this->attributes[$key] ?? null;
    }

    /** Значения в формате БД: колонка => скаляр (только инициализированные свойства). */
    public function getAttributes(): array
    {
        $vars = get_object_vars($this);
        $out = [];

        foreach (static::metadata()->fields as $prop => $field) {
            if (array_key_exists($prop, $vars)) {
                $out[$field->column] = Caster::toDb($vars[$prop]);
            }
        }

        foreach ($this->attributes as $key => $value) {
            $out[$key] = Caster::toDb($value);
        }

        return $out;
    }

    public function syncOriginal(): static
    {
        $this->original = $this->getAttributes();

        return $this;
    }

    public function getOriginal(?string $key = null): mixed
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    /** @return array<string, mixed> колонка => новое значение */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->getAttributes() as $column => $value) {
            if (!array_key_exists($column, $this->original) || !self::same($this->original[$column], $value)) {
                $dirty[$column] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();

        if ($key === null) {
            return $dirty !== [];
        }

        $prop = $this->resolveProperty($key);
        $column = $prop !== null ? static::metadata()->fields[$prop]->column : $key;

        return array_key_exists($column, $dirty);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return $a === $b || (is_scalar($a) && is_scalar($b) && (string) $a === (string) $b);
    }

    # ----------------------------------------------------------- persistence

    public function getTable(): string
    {
        return static::metadata()->table;
    }

    public function getKeyName(): string
    {
        return static::metadata()->primaryColumn;
    }

    public function getQualifiedKeyName(): string
    {
        return $this->getTable() . '.' . $this->getKeyName();
    }

    public function getKey(): mixed
    {
        return $this->getAttribute(static::metadata()->primaryProperty);
    }

    public static function query(): Builder
    {
        $model = new static();

        return new Builder($model, $model->getConnection()->table($model->getTable()));
    }

    public function save(): bool
    {
        if ($this->fireModelEvent('saving', true) === false) {
            return false;
        }

        $saved = $this->exists ? $this->performUpdate() : $this->performInsert();

        $saved && $this->fireModelEvent('saved');

        return $saved;
    }

    private function performInsert(): bool
    {
        if ($this->fireModelEvent('creating', true) === false) {
            return false;
        }

        $meta = static::metadata();
        $this->touchTimestamps(creating: true);

        $data = $this->getAttributes();
        $pk = $meta->primaryColumn;

        if (($data[$pk] ?? null) === null) {
            unset($data[$pk]);
        }

        $query = $this->getConnection()->table($this->getTable());

        if ($meta->incrementing && !array_key_exists($pk, $data)) {
            $this->setAttribute($meta->primaryProperty, $query->insertGetId($data));
        } else {
            $query->insert($data);
        }

        $this->exists = true;
        $this->syncOriginal();
        $this->fireModelEvent('created');

        return true;
    }

    private function performUpdate(): bool
    {
        if ($this->getDirty() === []) {
            return true;
        }

        $this->assertMutable($this->getDirty());

        if ($this->fireModelEvent('updating', true) === false) {
            return false;
        }

        # Слушатель мог изменить модель.
        $this->assertMutable($this->getDirty());
        $this->touchTimestamps(creating: false);

        $dirty = $this->getDirty();
        $old = $this->original;

        $this->getConnection()->table($this->getTable())
            ->where(static::metadata()->primaryColumn, '=', $this->getKey())
            ->update($dirty);

        $this->syncOriginal();
        $this->fireModelEvent('updated');
        $this->dispatchWatchers($dirty, $old);

        return true;
    }

    /** @param array<string, mixed> $dirty */
    private function assertMutable(array $dirty): void
    {
        foreach ($dirty as $column => $_) {
            $field = static::metadata()->fieldByColumn($column);

            if ($field !== null && $field->immutable) {
                throw new ImmutableAttributeException(static::class, $field->property);
            }
        }
    }

    /** @param array<string, mixed> $dirty @param array<string, mixed> $old */
    private function dispatchWatchers(array $dirty, array $old): void
    {
        if (self::$events === null) {
            return;
        }

        foreach ($dirty as $column => $_) {
            $field = static::metadata()->fieldByColumn($column);

            if ($field?->watch !== null) {
                self::$events->dispatch(WatchEventFactory::make(
                    $field->watch,
                    $this,
                    $field->property,
                    Caster::fromDb($old[$column] ?? null, $field->cast),
                    $this->getAttribute($field->property),
                ));
            }
        }
    }

    private function touchTimestamps(bool $creating): void
    {
        if (!static::metadata()->timestamps) {
            return;
        }

        $now = date(DateCaster::FORMAT);

        if ($creating && $this->getAttribute('created_at') === null) {
            $this->setAttribute('created_at', $now);
        }

        $this->setAttribute('updated_at', $now);
    }

    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if ($this->fireModelEvent('deleting', true) === false) {
            return false;
        }

        $this->getConnection()->table($this->getTable())
            ->where($this->getKeyName(), '=', $this->getKey())
            ->delete();

        $this->exists = false;
        $this->fireModelEvent('deleted');

        return true;
    }

    public function refresh(): static
    {
        $row = $this->getConnection()->table($this->getTable())
            ->where($this->getKeyName(), '=', $this->getKey())
            ->first();

        return $row === null ? $this : $this->hydrateRow($row);
    }

    public function fresh(): ?static
    {
        return static::find($this->getKey());
    }

    /** Запрос без eager-загрузок (его использует Collection::load()). */
    public function newQueryWithoutRelationships(): Builder
    {
        return static::query()->withoutEagerLoads();
    }

    public function newQueryWithoutScopes(): Builder
    {
        return static::query();
    }

    public function newModelQuery(): Builder
    {
        return static::query()->withoutEagerLoads();
    }

    # ------------------------------------------------------- static shortcuts

    /** Коллекция всех моделей. */
    public static function all(): mixed
    {
        return static::query()->get();
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    public static function findOrFail(int|string $id): static
    {
        return static::query()->findOrFail($id);
    }

    public static function create(array $attributes = []): static
    {
        return static::query()->create($attributes);
    }

    public static function destroy(int|string ...$ids): int
    {
        $count = 0;

        foreach ($ids as $id) {
            $count += (int) (static::find($id)?->delete() ?? false);
        }

        return $count;
    }

    /** `Product::factory()->count(3)->create()` */
    public static function factory(?int $count = null, array|\Closure $state = []): Factory
    {
        $factory = (self::$factories ?? throw new LogicException('FactoryManager не подключён (ORMServiceProvider).'))
            ->make(static::class);

        if ($count !== null) {
            $factory = $factory->count($count);
        }

        return $state === [] ? $factory : $factory->state($state);
    }

    # --------------------------------------------- validation / watch metadata

    /** @return array<string, list<string|object>> свойство => правила */
    public static function validationRules(): array
    {
        return array_map(static fn ($f) => $f->rules, static::metadata()->fields);
    }

    /** @return array<string, array<string, string>> свойство => [правило => сообщение] */
    public static function validationMessages(): array
    {
        return array_map(static fn ($f) => $f->messages, static::metadata()->fields);
    }

    /** Валидатор на правилах и сообщениях из #[Rules] / #[Config]. Данные ключуются именами свойств. */
    public static function validator(array $data): Validator
    {
        $messages = [];

        foreach (static::validationMessages() as $property => $byRule) {
            foreach ($byRule as $rule => $text) {
                $messages[$property . '.' . $rule] = $text;
            }
        }

        return Validator::make($data, array_filter(static::validationRules()), $messages);
    }

    /**
     * Проверить данные и вернуть только поля с правилами.
     *
     * @throws \Neo\Information\Validator\ValidationException
     */
    public static function validate(array $data): array
    {
        return static::validator($data)->validate();
    }

    /** @return array<string, class-string> свойство => событие */
    public static function watchedProperties(): array
    {
        $out = [];

        foreach (static::metadata()->fields as $prop => $field) {
            if ($field->watch !== null) {
                $out[$prop] = $field->watch;
            }
        }

        return $out;
    }

    # -------------------------------------------------------------- relations

    public function relation(string $name): Relation
    {
        $def = static::metadata()->relations[$name]
            ?? throw new LogicException(static::class . " не имеет отношения [{$name}].");

        return RelationFactory::make($this, $def);
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relationCache);
    }

    public function getRelation(string $name): mixed
    {
        return $this->relationCache[$name] ?? null;
    }

    /** Догрузить отношения одним запросом на отношение: `$post->load('comments.user')`. */
    public function load(string|array ...$relations): static
    {
        EagerLoader::load([$this], EagerLoader::normalize($relations));

        return $this;
    }

    /** Как load(), но пропускает уже загруженное. */
    public function loadMissing(string|array ...$relations): static
    {
        EagerLoader::loadMissing([$this], EagerLoader::normalize($relations));

        return $this;
    }

    public function setRelation(string $name, mixed $value): static
    {
        $this->relationCache[$name] = $value;

        return $this;
    }

    # ---------------------------------------------------------- serialization

    public function toArray(): array
    {
        $meta = static::metadata();
        $vars = get_object_vars($this);
        $out = [];

        foreach ($meta->fields as $prop => $field) {
            if (!$field->hidden && array_key_exists($prop, $vars)) {
                $out[$prop] = self::plain($vars[$prop]);
            }
        }

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $meta->classicHidden, true)) {
                $out[$key] = self::plain($value);
            }
        }

        foreach ($this->relationCache as $name => $related) {
            $out[$name] = is_array($related)
                ? array_map(static fn ($m) => $m instanceof self ? $m->toArray() : $m, $related)
                : ($related instanceof self ? $related->toArray() : $related);
        }

        return $out;
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        };
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    # ------------------------------------------------------------------ magic

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->relationCache)) {
            return $this->relationCache[$name];
        }

        if (isset(static::metadata()->relations[$name])) {
            if (self::$preventLazyLoading && $this->fromQuery) {
                $this->violateLazyLoading($name);
            }

            return $this->relationCache[$name] = $this->relation($name)->getResults();
        }

        return $this->attributes[$name] ?? null;
    }

    private function violateLazyLoading(string $name): void
    {
        if (self::$lazyLoadingHandler === null) {
            throw new LazyLoadingViolationException(static::class, $name);
        }

        (self::$lazyLoadingHandler)($this, $name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relationCache[$name]) || isset(static::metadata()->relations[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name], $this->relationCache[$name]);
    }

    public function __call(string $method, array $args): mixed
    {
        if (isset(static::metadata()->relations[$method])) {
            return $this->relation($method);
        }

        return static::query()->{$method}(...$args);
    }

    public static function __callStatic(string $method, array $args): mixed
    {
        return static::query()->{$method}(...$args);
    }
}
