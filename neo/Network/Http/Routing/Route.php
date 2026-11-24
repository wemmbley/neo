<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use Closure;
use InvalidArgumentException;
use Neo\Information\Structures\Arr;

/**
 * Описание маршрута. После регистрации (boot) объект НЕ меняется и разделяется всеми
 * запросами; параметры конкретного запроса живут в копии, которую делает {@see bound()}.
 * Так маршрутизатор безопасен при одновременной обработке запросов (Swoole).
 */
class Route
{
    /** @var list<string> */
    public array $methods;

    protected string $uri;

    /** @var array<string, mixed> */
    protected array $action;

    /** @var array<string, string> */
    protected array $wheres = [];

    /** @var array<string, mixed> */
    protected array $defaults = [];

    /** @var array{regex: string, names: list<string>, fields: array<string, string>, domain: ?array{regex: string, names: list<string>}}|null */
    protected ?array $compiled = null;

    /** @var array<string, mixed>|null параметры запроса; null у «исходного» маршрута */
    protected ?array $parameters = null;

    protected ?Route $origin = null;

    protected ?array $computedMiddleware = null;

    public bool $isFallback = false;

    /**
     * @param list<string>|string $methods
     * @param Closure|array<mixed>|string|null $action
     */
    public function __construct(array|string $methods, string $uri, Closure|array|string|null $action)
    {
        $methods = array_map('strtoupper', (array) $methods);

        if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
            $methods[] = 'HEAD';
        }

        $this->methods = array_values(array_unique($methods));
        $this->uri = '/' . trim($uri, '/');
        $this->action = self::parseAction($action);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function parseAction(Closure|array|string|null $action): array
    {
        if ($action === null) {
            return ['uses' => static fn () => throw new \LogicException('Маршрут не имеет действия.')];
        }

        if ($action instanceof Closure) {
            return ['uses' => $action];
        }

        if (is_string($action)) {
            return ['uses' => $action, 'controller' => $action];
        }

        # [Controller::class, 'method'] или ['uses' => ..., 'middleware' => ...]
        if (array_is_list($action) && count($action) === 2 && is_string($action[0]) && is_string($action[1])) {
            return ['uses' => $action, 'controller' => $action[0] . '@' . $action[1]];
        }

        if (is_array($action) && isset($action[0]) && $action[0] instanceof Closure) {
            return ['uses' => $action[0]];
        }

        if (isset($action['uses']) && is_string($action['uses']) && !isset($action['controller'])) {
            $action['controller'] = $action['uses'];
        }

        return $action;
    }

    // ------------------------------------------------------------------
    // Адрес
    // ------------------------------------------------------------------

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function setUri(string $uri): static
    {
        $this->uri = '/' . trim($uri, '/');
        $this->compiled = null;

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $prefix = trim($prefix, '/');

        return $this->setUri($prefix === '' ? $this->uri : $prefix . '/' . trim($this->uri, '/'));
    }

    public function domain(?string $domain = null): static|string|null
    {
        if ($domain === null) {
            return $this->action['domain'] ?? null;
        }

        $this->action['domain'] = $domain;
        $this->compiled = null;

        return $this;
    }

    public function getDomain(): ?string
    {
        return isset($this->action['domain']) ? (string) $this->action['domain'] : null;
    }

    // ------------------------------------------------------------------
    // Имя
    // ------------------------------------------------------------------

    /**
     * Задаёт имя. Если у группы был префикс имён, он уже лежит в action['as'] и имя дописывается.
     */
    public function name(string $name): static
    {
        $this->action['as'] = ($this->action['as'] ?? '') . $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->action['as'] ?? null;
    }

    public function named(string ...$names): bool
    {
        $name = $this->getName();

        foreach ($names as $pattern) {
            if ($name !== null && \Neo\Information\Structures\Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Действие
    // ------------------------------------------------------------------

    public function getAction(?string $key = null): mixed
    {
        return $key === null ? $this->action : Arr::get($this->action, $key);
    }

    /** @param array<string, mixed> $action */
    public function setAction(array $action): static
    {
        $this->action = $action;
        $this->compiled = null;

        return $this;
    }

    public function isControllerAction(): bool
    {
        return !($this->action['uses'] instanceof Closure);
    }

    public function getActionName(): string
    {
        return $this->action['controller'] ?? 'Closure';
    }

    /** @return class-string|null */
    public function getControllerClass(): ?string
    {
        if (!$this->isControllerAction()) {
            return null;
        }

        return $this->parseController()[0];
    }

    public function getControllerMethod(): ?string
    {
        if (!$this->isControllerAction()) {
            return null;
        }

        return $this->parseController()[1];
    }

    /**
     * @return array{0: class-string, 1: string}
     */
    public function parseController(): array
    {
        $uses = $this->action['uses'];

        if (is_array($uses)) {
            return [$uses[0], $uses[1]];
        }

        if (is_string($uses) && str_contains($uses, '@')) {
            return explode('@', $uses, 2);
        }

        return [(string) $uses, '__invoke'];
    }

    // ------------------------------------------------------------------
    // Middleware
    // ------------------------------------------------------------------

    /**
     * Без аргументов возвращает список, с аргументом добавляет middleware.
     *
     * @param array<mixed>|string|Closure|null $middleware
     * @return static|list<mixed>
     */
    public function middleware(array|string|Closure|null $middleware = null): static|array
    {
        if ($middleware === null) {
            return (array) ($this->action['middleware'] ?? []);
        }

        $this->action['middleware'] = array_merge(
            (array) ($this->action['middleware'] ?? []),
            is_array($middleware) ? $middleware : [$middleware],
        );

        return $this;
    }

    /** @param array<string>|string $middleware */
    public function withoutMiddleware(array|string $middleware): static
    {
        $this->action['excluded_middleware'] = array_merge(
            (array) ($this->action['excluded_middleware'] ?? []),
            (array) $middleware,
        );

        return $this;
    }

    /** @return list<string> */
    public function excludedMiddleware(): array
    {
        return (array) ($this->action['excluded_middleware'] ?? []);
    }

    /**
     * Кэш итогового списка middleware живёт в исходном маршруте: рассчитывается
     * один раз и дальше только читается.
     *
     * @param Closure(self): list<mixed> $resolver
     * @return list<mixed>
     */
    public function computedMiddleware(Closure $resolver): array
    {
        $target = $this->origin ?? $this;

        return $target->computedMiddleware ??= $resolver($target);
    }

    // ------------------------------------------------------------------
    // Ограничения параметров
    // ------------------------------------------------------------------

    /** @param array<string, string>|string $name */
    public function where(array|string $name, ?string $expression = null): static
    {
        foreach (is_array($name) ? $name : [$name => $expression] as $key => $pattern) {
            $this->wheres[$key] = (string) $pattern;
        }

        $this->compiled = null;

        return $this;
    }

    /** @param array<string>|string $parameters */
    public function whereNumber(array|string $parameters): static
    {
        return $this->assignWhere($parameters, '[0-9]+');
    }

    public function whereAlpha(array|string $parameters): static
    {
        return $this->assignWhere($parameters, '[a-zA-Z]+');
    }

    public function whereAlphaNumeric(array|string $parameters): static
    {
        return $this->assignWhere($parameters, '[a-zA-Z0-9]+');
    }

    public function whereUuid(array|string $parameters): static
    {
        return $this->assignWhere($parameters, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    }

    public function whereUlid(array|string $parameters): static
    {
        return $this->assignWhere($parameters, '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}');
    }

    /** @param list<string> $values */
    public function whereIn(array|string $parameters, array $values): static
    {
        return $this->assignWhere($parameters, implode('|', array_map(static fn ($v): string => preg_quote((string) $v, '#'), $values)));
    }

    private function assignWhere(array|string $parameters, string $expression): static
    {
        foreach ((array) $parameters as $parameter) {
            $this->wheres[$parameter] = $expression;
        }

        $this->compiled = null;

        return $this;
    }

    /** @return array<string, string> */
    public function wheres(): array
    {
        return $this->wheres;
    }

    public function defaults(string $key, mixed $value): static
    {
        $this->defaults[$key] = $value;

        return $this;
    }

    /** Значение по умолчанию для необязательного параметра. */
    public function setDefaults(array $defaults): static
    {
        $this->defaults = $defaults;

        return $this;
    }

    // ------------------------------------------------------------------
    // Компиляция и сопоставление
    // ------------------------------------------------------------------

    /** Нет ли в адресе и домене параметров: такие маршруты ищутся по хеш-таблице. */
    public function isStatic(): bool
    {
        return !str_contains($this->uri, '{') && $this->getDomain() === null && !$this->isFallback;
    }

    /**
     * @return array{regex: string, names: list<string>, fields: array<string, string>, domain: ?array{regex: string, names: list<string>}}
     */
    public function compile(): array
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $names = [];
        $fields = [];
        $regex = $this->isFallback ? '.*' : $this->compilePattern($this->uri, '[^/]+', $names, $fields, true);

        $domain = null;
        if ($this->getDomain() !== null) {
            $domainNames = [];
            $domainRegex = $this->compilePattern(strtolower($this->getDomain()), '[^.]+', $domainNames, $fields, false);
            $domain = ['regex' => '#^' . $domainRegex . '$#sDu', 'names' => $domainNames];
            $names = [...$domainNames, ...$names];
        }

        return $this->compiled = [
            'regex' => '#^' . $regex . '$#sDu',
            'names' => $names,
            'fields' => $fields,
            'domain' => $domain,
        ];
    }

    /**
     * @param list<string> $names
     * @param array<string, string> $fields
     */
    private function compilePattern(string $uri, string $default, array &$names, array &$fields, bool $path): string
    {
        $token = '/^(\/?)\{([A-Za-z_]\w*)(?::(\w+))?(\?)?\}$/';
        $parts = preg_split('/(\/?\{[A-Za-z_]\w*(?::\w+)?\??\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';

        foreach ($parts as $part) {
            if (preg_match($token, $part, $m) !== 1) {
                $regex .= preg_quote($part, '#');
                continue;
            }

            [, $slash, $name, $field, $optional] = array_pad($m, 5, '');

            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException("Параметр {{$name}} повторяется в маршруте [{$uri}].");
            }

            $names[] = $name;

            if ($field !== '') {
                $fields[$name] = $field;
            }

            $pattern = str_replace('#', '\#', $this->wheres[$name] ?? $default);
            $group = '(?P<' . $name . '>' . $pattern . ')';

            if ($optional === '?') {
                $regex .= '(?:' . ($path && $slash !== '' ? '/' : '') . $group . ')?';
            } else {
                $regex .= ($path && $slash !== '' ? '/' : '') . $group;
            }
        }

        return $regex;
    }

    /**
     * Подходит ли путь (и хост) маршруту.
     *
     * @return array<string, mixed>|null параметры или null
     */
    public function matches(string $path, string $host = ''): ?array
    {
        $compiled = $this->compile();
        $parameters = [];

        if ($compiled['domain'] !== null) {
            if (preg_match($compiled['domain']['regex'], $host, $m) !== 1) {
                return null;
            }

            foreach ($compiled['domain']['names'] as $name) {
                $parameters[$name] = $m[$name] ?? null;
            }
        }

        if (preg_match($compiled['regex'], $path, $m) !== 1) {
            return null;
        }

        foreach ($compiled['names'] as $name) {
            if (array_key_exists($name, $parameters)) {
                continue;
            }

            $value = $m[$name] ?? '';
            $parameters[$name] = $value === '' ? ($this->defaults[$name] ?? null) : $value;
        }

        if ($this->isFallback) {
            $parameters['fallbackPlaceholder'] = ltrim($path, '/');
        }

        return $parameters;
    }

    /** @return list<string> */
    public function parameterNames(): array
    {
        return $this->compile()['names'];
    }

    public function bindingField(string $parameter): ?string
    {
        return $this->compile()['fields'][$parameter] ?? null;
    }

    // ------------------------------------------------------------------
    // Параметры конкретного запроса
    // ------------------------------------------------------------------

    /** Копия маршрута с параметрами запроса. Исходный маршрут остаётся неизменным. */
    public function bound(array $parameters): static
    {
        $copy = clone $this;
        $copy->origin = $this->origin ?? $this;
        $copy->parameters = $parameters;

        return $copy;
    }

    public function original(): static
    {
        return $this->origin ?? $this;
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters ?? [];
    }

    public function parameter(string $name, mixed $default = null): mixed
    {
        return Arr::get($this->parameters ?? [], $name, $default);
    }

    public function hasParameter(string $name): bool
    {
        return array_key_exists($name, $this->parameters ?? []);
    }

    /** Меняет параметр в КОПИИ запроса (подстановка модели). */
    public function setParameter(string $name, mixed $value): static
    {
        if ($this->parameters === null) {
            throw new \LogicException('Параметры можно менять только у маршрута, найденного для запроса.');
        }

        $this->parameters[$name] = $value;

        return $this;
    }

    /** @return array<string, mixed> параметры без null (необязательные пропущенные) */
    public function parametersWithoutNulls(): array
    {
        return array_filter($this->parameters(), static fn ($p): bool => $p !== null);
    }
}
