<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use BackedEnum;
use InvalidArgumentException;
use LogicException;
use Neo\Information\Persistence\Database\Schema\Declarative\Introspector;
use Neo\Information\Persistence\ORM\Metadata\ModelMetadata;
use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Structures\Arr;
use Neo\Information\Structures\Str;
use Neo\Network\Http\Contracts\RoutableResource;
use Neo\Network\Http\Routing\Router;
use Neo\Network\ResourceAPI\Exceptions\ColumnNotFoundResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\ModelNotFoundResourceAPIException;
use ReflectionEnum;

/**
 * Описание REST-ресурса поверх модели. Достаточно класса и одной строки в маршрутах:
 *
 *   final class PostResourceAPI extends ResourceAPI {}      // модель Post, адрес /posts
 *   Route::resource(PostResourceAPI::class);
 *
 * или без класса: `Route::resource(ResourceAPI::make(Post::class)->sortable(['id', 'title']))`.
 *
 * Ресурс только описывает, что разрешено. Он ничего не знает про запрос и не хранит его данных,
 * поэтому один объект обслуживает все запросы. Настраивается свойствами класса либо цепочкой
 * методов до регистрации маршрутов, после неё меняться перестаёт.
 */
class ResourceAPI implements RoutableResource
{
    public const array ACTIONS = ['index', 'show', 'store', 'update', 'destroy'];

    /** Операторы условий (логические `$or` и `$and` разрешены всегда: они лишь объединяют условия). */
    public const array OPERATORS = [
        '$eq', '$eqc', '$ne', '$lt', '$lte', '$gt', '$gte', '$in', '$notIn', '$null',
        '$between', '$notBetween', '$contains', '$notContains', '$containsc', '$notContainsc',
        '$startsWith', '$startsWithc', '$endsWith', '$endsWithc', '$exists',
    ];

    private const array DEFAULTS = [
        'null_last' => true,
        'relation_deep' => 3,
        'pagination' => ['per_page' => 15, 'max_per_page' => 50],
        'filters' => ['max_conditions' => 20, 'max_in_items' => 100, 'max_value_length' => 255],
        'documentation' => ['enabled' => true],
        'models_namespaces' => ['App\\Database\\Models\\', 'App\\Models\\'],
        'debug' => false,
    ];

    /** @var array<string, mixed> */
    private static array $config = [];

    /** Класс модели. Не задан: берётся из имени ресурса (PostResourceAPI => Post). */
    protected ?string $model = null;

    /** Имя в адресе. Не задано: множественное snake_case от имени модели (`posts`). */
    protected ?string $name = null;

    /** Разрешена ли сортировка / фильтрация. */
    protected bool $sort = true;
    protected bool $filter = true;

    /** Поля фильтрации и сортировки. null: все видимые. Скрытые недоступны никогда. */
    protected ?array $filterFields = null;
    protected ?array $sortFields = null;

    /** Поля, по которым работает `?search=`. Пусто: поиска нет. */
    protected array $searchFields = [];

    /** Видимые поля ответа. null: все, кроме скрытых в модели. */
    protected ?array $fields = null;

    /** Переименованные поля `['email' => 'mail']`: клиент видит только новые имена. */
    protected array $renamedFields = [];

    /** Разрешённые операторы. Пусто: все. */
    protected array $filterOperators = [];

    /** Кастомные фильтры: классы {@see FilterResourceAPI} или их имена из папок addFiltersPath(). */
    protected array $filters = [];

    /** Связи, доступные в `?include=`. */
    protected array $include = [];

    /** Правила записи: массив правил валидатора или класс FormRequest. */
    protected array|string $rules = [];

    protected array $actions = ['index', 'show', 'store'];

    /** Записей на страницу по умолчанию. null: `pagination.per_page`. */
    protected ?int $perPage = null;

    /** @var list<array{0: string, 1: string|null}> */
    private static array $filterPaths = [];

    private bool $frozen = false;

    /** @var array<string, mixed>|null */
    private ?array $prepared = null;

    /** @var array<string, ResourceFilter>|null */
    private ?array $customFilters = null;

    /** @param class-string<Model>|null $model */
    public static function make(?string $model = null): static
    {
        $resource = new static();

        if ($model !== null) {
            $resource->model = $model;
        }

        return $resource;
    }

    // ------------------------------------------------------------------
    // Настройки приложения
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $config значения `resourceapi.*` (провайдер передаёт их при загрузке) */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function setting(string $key): mixed
    {
        return Arr::get(self::$config, $key, Arr::get(self::DEFAULTS, $key));
    }

    /**
     * Папка с кастомными фильтрами (классы `*Filter`, наследники FilterResourceAPI).
     * В `$filters` ресурса фильтр можно указать коротким именем класса.
     */
    public static function addFiltersPath(string $directory, ?string $namespace = null): void
    {
        self::$filterPaths[] = [rtrim($directory, '/\\'), $namespace];
    }

    // ------------------------------------------------------------------
    // Цепочка настройки
    // ------------------------------------------------------------------

    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    public function fields(array $fields): static
    {
        return $this->set('fields', $fields);
    }

    public function filterable(array|bool $fields = true): static
    {
        return is_bool($fields) ? $this->set('filter', $fields) : $this->set('filterFields', $fields);
    }

    public function sortable(array|bool $fields = true): static
    {
        return is_bool($fields) ? $this->set('sort', $fields) : $this->set('sortFields', $fields);
    }

    public function searchable(array $fields): static
    {
        return $this->set('searchFields', $fields);
    }

    public function rename(array $fields): static
    {
        return $this->set('renamedFields', $fields);
    }

    public function operators(array $operators): static
    {
        return $this->set('filterOperators', $operators);
    }

    public function withFilters(array $filters): static
    {
        return $this->set('filters', $filters);
    }

    public function include(array $relations): static
    {
        return $this->set('include', $relations);
    }

    public function rules(array|string $rules): static
    {
        return $this->set('rules', $rules);
    }

    public function actions(array $actions): static
    {
        return $this->set('actions', $actions);
    }

    public function paginate(int $perPage): static
    {
        return $this->set('perPage', $perPage);
    }

    private function set(string $property, mixed $value): static
    {
        if ($this->frozen) {
            throw new LogicException('Ресурс «' . $this->resourceName() . '» уже зарегистрирован, его нельзя менять.');
        }

        $this->{$property} = $value;

        return $this;
    }

    // ------------------------------------------------------------------
    // Маршруты
    // ------------------------------------------------------------------

    public function registerRoutes(Router $router, array $options = []): static
    {
        if (isset($options['actions'])) {
            $this->set('actions', (array) $options['actions']);
        }

        $this->frozen = true;

        foreach ($this->actions as $action) {
            if (!in_array($action, self::ACTIONS, true)) {
                throw new InvalidArgumentException("Ресурс «{$this->resourceName()}»: неизвестное действие «{$action}».");
            }
        }

        $router->getContainer()->make(ResourceController::class)->register($router, $this);

        return $this;
    }

    // ------------------------------------------------------------------
    // Что за ресурс
    // ------------------------------------------------------------------

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        $base = Str::basename(static::class);
        $short = str_ends_with($base, 'ResourceAPI') ? substr($base, 0, -11) : $base;
        $pos = strrpos(static::class, '\\');
        $own = $pos === false ? '' : substr(static::class, 0, $pos + 1);

        foreach ([...(array) self::setting('models_namespaces'), $own] as $namespace) {
            $class = ltrim(rtrim((string) $namespace, '\\') . '\\' . $short, '\\');

            if ($short !== '' && is_subclass_of($class, Model::class)) {
                return $this->model = $class;
            }
        }

        throw new ModelNotFoundResourceAPIException("Requested model {$short} was not found.");
    }

    /** Имя ресурса в адресе. */
    public function resourceName(): string
    {
        return $this->name ?? Str::plural(Str::snake(Str::basename($this->modelClass())));
    }

    /** @return list<string> */
    public function enabledActions(): array
    {
        return array_values(array_intersect(self::ACTIONS, $this->actions));
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    public function sortEnabled(): bool
    {
        return $this->sort;
    }

    public function filterEnabled(): bool
    {
        return $this->filter;
    }

    public function perPage(): int
    {
        $max = (int) self::setting('pagination.max_per_page');

        return max(1, min($this->perPage ?? (int) self::setting('pagination.per_page'), $max));
    }

    public function rulesDefinition(): array|string
    {
        return $this->rules;
    }

    /** @return list<string> */
    public function includeList(): array
    {
        return array_values($this->include);
    }

    // ------------------------------------------------------------------
    // Поля
    // ------------------------------------------------------------------

    /**
     * Видимые поля: колонка => описание. Скрытые и не вошедшие в `$fields` здесь отсутствуют,
     * поэтому для всего остального (фильтр, сортировка, ответ, запись) их просто не существует.
     *
     * @return array<string, array{name: string, type: string, nullable: bool, primary: bool, fillable: bool}>
     */
    public function exposed(): array
    {
        return $this->prepare()['exposed'];
    }

    /** Имя колонки по имени, которое видит клиент, или null. */
    public function columnOf(string $clientName): ?string
    {
        return $this->prepare()['by_name'][$clientName] ?? null;
    }

    /** Имя для клиента по колонке. */
    public function nameOf(string $column): ?string
    {
        return $this->prepare()['exposed'][$column]['name'] ?? null;
    }

    public function isFilterable(string $column): bool
    {
        return isset($this->prepare()['filterable'][$column]);
    }

    public function isSortable(string $column): bool
    {
        return isset($this->prepare()['sortable'][$column]);
    }

    /** @return list<string> колонки */
    public function searchColumns(): array
    {
        return array_keys($this->prepare()['searchable']);
    }

    /** @return list<string> колонки */
    public function filterColumns(): array
    {
        return array_keys($this->prepare()['filterable']);
    }

    /** @return list<string> колонки */
    public function sortColumns(): array
    {
        return array_keys($this->prepare()['sortable']);
    }

    /** Колонка, разрешённая для операции, или исключение (одно и то же для скрытой, чужой и несуществующей). */
    public function requireColumn(string $clientName, string $purpose): string
    {
        $column = $this->columnOf($clientName);
        $allowed = match ($purpose) {
            'filter' => $column !== null && $this->isFilterable($column),
            'sort' => $column !== null && $this->isSortable($column),
            default => $column !== null,
        };

        if (!$allowed) {
            throw new ColumnNotFoundResourceAPIException(
                self::setting('debug') ? "Field [{$clientName}] is not available for {$purpose} in resource {$this->resourceName()}." : '',
                $clientName,
            );
        }

        return $column;
    }

    public function operatorAllowed(string $operator): bool
    {
        if ($operator === '$or' || $operator === '$and') {
            return true;
        }

        if (!in_array($operator, self::OPERATORS, true)) {
            return false;
        }

        return $this->prepare()['operators'] === [] || in_array($operator, $this->prepare()['operators'], true);
    }

    /** @return list<string> */
    public function allowedOperators(): array
    {
        return $this->prepare()['operators'] ?: self::OPERATORS;
    }

    /** @return array<string, \Neo\Information\Persistence\ORM\Attributes\Relations\RelationAttribute> */
    public function relations(): array
    {
        return ModelMetadata::for($this->modelClass())->relations;
    }

    /** Колонки, которые можно писать: есть в `$fillable`, видимы и для них есть правило. @return array<string, string> имя клиента => колонка */
    public function writable(): array
    {
        return $this->prepare()['writable'];
    }

    /** Правила записи по именам колонок. @return array<string, mixed> */
    public function ruleSet(): array
    {
        return $this->prepare()['rules'];
    }

    /** @return array<string, ResourceFilter> имя => фильтр */
    public function customFilters(): array
    {
        if ($this->customFilters !== null) {
            return $this->customFilters;
        }

        $result = [];

        foreach ($this->filters as $item) {
            $filter = $item instanceof ResourceFilter ? $item : $this->resolveFilter((string) $item);
            $result[$filter->name()] = $filter;
        }

        return $this->customFilters = $result;
    }

    private function resolveFilter(string $name): ResourceFilter
    {
        $class = class_exists($name) ? $name : $this->findFilterClass($name);

        if ($class === null || !is_subclass_of($class, ResourceFilter::class)) {
            throw new LogicException("Ресурс «{$this->resourceName()}»: фильтр «{$name}» не найден или не наследует FilterResourceAPI.");
        }

        return new $class();
    }

    private function findFilterClass(string $name): ?string
    {
        foreach (self::$filterPaths as [$directory, $namespace]) {
            $file = $directory . '/' . $name . '.php';

            if (!is_file($file)) {
                continue;
            }

            if ($namespace !== null && class_exists($class = rtrim($namespace, '\\') . '\\' . $name)) {
                return $class;
            }

            $before = get_declared_classes();
            require_once $file;

            foreach (array_diff(get_declared_classes(), $before) as $class) {
                if (is_subclass_of($class, ResourceFilter::class)) {
                    return $class;
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Схема (для /schema и документации)
    // ------------------------------------------------------------------

    /** Описание ресурса для клиента. Имён классов не содержит. */
    public function schema(): array
    {
        $prepared = $this->prepare();
        $fields = [];

        foreach ($prepared['exposed'] as $column => $info) {
            $fields[] = [
                'name' => $info['name'],
                'type' => $info['type'],
                'nullable' => $info['nullable'],
                'primary' => $info['primary'],
                'filterable' => $this->filter && isset($prepared['filterable'][$column]),
                'sortable' => $this->sort && isset($prepared['sortable'][$column]),
                'searchable' => isset($prepared['searchable'][$column]),
                'writable' => in_array($column, $prepared['writable'], true),
            ];
        }

        return [
            'resource' => $this->resourceName(),
            'fields' => $fields,
            'actions' => $this->enabledActions(),
            'operators' => $this->filter ? $this->allowedOperators() : [],
            'filters' => array_keys($this->customFilters()),
            'include' => $this->includeList(),
            'pagination' => ['per_page' => $this->perPage(), 'max_per_page' => (int) self::setting('pagination.max_per_page')],
        ];
    }

    // ------------------------------------------------------------------
    // Внутреннее: разбор модели (лениво, один раз)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function prepare(): array
    {
        if ($this->prepared !== null) {
            return $this->prepared;
        }

        $class = $this->modelClass();
        $meta = ModelMetadata::for($class);
        $model = new $class();
        $sql = $this->sqlTypes($model);

        $columns = array_keys($sql);

        foreach ($meta->fields as $field) {
            $columns[] = $field->column;
        }

        foreach ($meta->classicCasts as $column => $_) {
            $columns[] = (string) $column;
        }

        $columns[] = $meta->primaryColumn;
        $columns = array_values(array_unique($columns));

        $fieldByColumn = [];
        foreach ($meta->fields as $field) {
            $fieldByColumn[$field->column] = $field;
        }

        # Первый проход: что вообще видимо.
        $visible = [];
        foreach ($columns as $column) {
            $field = $fieldByColumn[$column] ?? null;
            $hidden = $field !== null ? $field->hidden : in_array($column, $meta->classicHidden, true);

            if ($hidden || ($this->fields !== null && !$this->listed($column, $this->fields))) {
                continue;
            }

            $visible[$column] = [
                'type' => self::typeOf($field?->cast ?? ($meta->classicCasts[$column] ?? null), $sql[$column] ?? null),
                'nullable' => $field?->nullable ?? true,
                'primary' => $column === $meta->primaryColumn,
                'fillable' => $column !== $meta->primaryColumn && $model->isFillable($column),
            ];
        }

        $renamed = [];
        foreach ($this->renamedFields as $from => $to) {
            $renamed[$this->toColumn((string) $from, $visible)] = (string) $to;
        }

        $exposed = [];
        $byName = [];
        foreach ($visible as $column => $info) {
            $name = $renamed[$column] ?? $column;

            if (isset($byName[$name])) {
                throw new LogicException("Ресурс «{$this->resourceName()}»: имя поля «{$name}» повторяется (переименование столкнулось с другим полем).");
            }

            $byName[$name] = $column;
            $exposed[$column] = ['name' => $name] + $info;
        }

        $operators = [];
        foreach ($this->filterOperators as $operator) {
            $operator = '$' . ltrim((string) $operator, '$');

            if (!in_array($operator, self::OPERATORS, true)) {
                throw new LogicException("Ресурс «{$this->resourceName()}»: неизвестный оператор «{$operator}».");
            }

            $operators[] = $operator;
        }

        $pick = fn (?array $declared): array => $declared === null
            ? array_fill_keys(array_keys($exposed), true)
            : array_fill_keys(array_values(array_filter(
                array_map(fn ($n) => $this->toColumn((string) $n, $exposed), $declared),
                static fn (?string $c) => $c !== null && isset($exposed[$c]),
            )), true);

        $searchable = array_intersect_key($pick($this->searchFields === [] ? [] : $this->searchFields), array_filter(
            $exposed,
            static fn (array $i) => $i['type'] === 'string',
        ));

        [$rules, $writable] = $this->writeRules($exposed);

        return $this->prepared = [
            'exposed' => $exposed,
            'by_name' => $byName,
            'filterable' => $pick($this->filterFields),
            'sortable' => $pick($this->sortFields),
            'searchable' => $searchable,
            'operators' => $operators,
            'rules' => $rules,
            'writable' => $writable,
        ];
    }

    /** Имя из определения ресурса (оригинальное или новое) в колонку. */
    private function toColumn(string $name, array $known): ?string
    {
        if (isset($known[$name])) {
            return $name;
        }

        foreach ($this->renamedFields as $from => $to) {
            if ($to === $name && isset($known[$from])) {
                return (string) $from;
            }
        }

        return null;
    }

    private function listed(string $column, array $list): bool
    {
        return in_array($column, $list, true)
            || (isset($this->renamedFields[$column]) && in_array($this->renamedFields[$column], $list, true));
    }

    /**
     * @param array<string, array> $exposed
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function writeRules(array $exposed): array
    {
        $rules = $this->rules;

        if (is_string($rules)) {
            $rules = $rules::create('/', 'POST')->rules();
        }

        $byColumn = [];
        $writable = [];

        foreach ($rules as $key => $rule) {
            $column = explode('.', (string) $key)[0];

            if (isset($exposed[$column]) && $exposed[$column]['fillable']) {
                $byColumn[(string) $key] = $rule;
                $writable[$exposed[$column]['name']] = $column;
            }
        }

        return [$byColumn, $writable];
    }

    /** @return array<string, string> колонка => тип SQL (пусто, если таблицы нет) */
    private function sqlTypes(Model $model): array
    {
        try {
            $connection = $model->getConnection();
            $introspector = new Introspector($connection);
            $table = $connection->withPrefix($model->getTable());

            if (!$introspector->exists($table)) {
                return [];
            }

            return array_map(
                static fn (array $c) => (string) ($c['sql'] ?? ''),
                $introspector->table($table)['columns'],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private static function typeOf(?string $cast, ?string $sql): string
    {
        if ($cast !== null) {
            $name = strtolower($cast);

            $type = match (true) {
                in_array($name, ['int', 'integer'], true) => 'int',
                in_array($name, ['float', 'double', 'real'], true), str_starts_with($name, 'decimal') => 'float',
                in_array($name, ['bool', 'boolean'], true) => 'bool',
                $name === 'string' => 'string',
                in_array($name, ['array', 'json', 'object'], true) => 'json',
                in_array($name, ['datetime', 'timestamp', 'immutable_datetime'], true) => 'datetime',
                in_array($name, ['date', 'immutable_date'], true) => 'date',
                default => null,
            };

            if ($type !== null) {
                return $type;
            }

            if (enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
                return (string) (new ReflectionEnum($cast))->getBackingType() === 'int' ? 'int' : 'string';
            }

            if (is_a($cast, \DateTimeInterface::class, true)) {
                return 'datetime';
            }
        }

        $sql = strtolower((string) $sql);

        return match (true) {
            str_contains($sql, 'bool'), str_contains($sql, 'tinyint(1)') => 'bool',
            str_contains($sql, 'int') => 'int',
            (bool) preg_match('/float|double|decimal|real|numeric/', $sql) => 'float',
            str_contains($sql, 'json') => 'json',
            str_contains($sql, 'datetime'), str_contains($sql, 'timestamp') => 'datetime',
            str_contains($sql, 'date') => 'date',
            default => 'string',
        };
    }
}
