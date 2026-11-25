<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Gate;

use Closure;
use InvalidArgumentException;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authorization\Attributes\UsePolicy;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionClass;

/**
 * Gate: единая точка вопроса «может ли ЭТОТ пользователь сделать ЭТО».
 *
 *   Gate::define('ban-user', fn (User $user) => $user->hasRole('moderator'));
 *   Gate::policy(Post::class, PostPolicy::class);
 *   Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
 *
 *   Gate::allows('ban-user');                     // текущий пользователь
 *   Gate::allows('update', $post);                // политика модели Post: PostPolicy::update($user, $post)
 *   Gate::allows('create', Post::class);          // PostPolicy::create($user)
 *   Gate::forUser($other)->denies('update', $post);
 *   Gate::authorize('update', $post);             // иначе AuthorizationException (403)
 *
 * Порядок решения: before-обработчики (первый не-null ответ окончателен), затем политика
 * модели из первого аргумента, затем способность из define(), затем after-обработчики
 * (могут дать ответ, только если его ещё нет). Нет ни политики, ни способности: ЗАПРЕТ.
 *
 * Содержит только описания (способности, политики, обработчики): данных запроса не хранит,
 * поэтому один экземпляр на процесс безопасен для Swoole. Текущего пользователя даёт
 * функция-резолвер (охранник по умолчанию), forUser() подменяет его на заданного.
 */
final class GateManager
{
    /** @var array<string, Closure|array<int, mixed>|string> */
    private array $abilities = [];

    /** @var array<class-string, class-string> */
    private array $policies = [];

    /** @var array<class-string, class-string|null> кеш «модель => класс политики» (экземпляры не кешируем) */
    private array $policyClasses = [];

    /** @var list<Closure> */
    private array $before = [];

    /** @var list<Closure> */
    private array $after = [];

    /** @param Closure(): ?Authenticatable $userResolver */
    public function __construct(
        private readonly ServiceContainer $container,
        private Closure $userResolver,
        private readonly string $policiesNamespace = 'App\\Policies\\',
    ) {}

    // ----------------------------------------------------------- регистрация

    /**
     * @param Closure|array{0: class-string|object, 1: string}|string $callback замыкание, [Класс, 'метод'] или 'Класс@метод'
     */
    public function define(string $ability, Closure|array|string $callback): static
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    /**
     * Набор способностей `имя.способность` из методов политики:
     * `Gate::resource('posts', PostPolicy::class)` даёт posts.viewAny, posts.view, posts.create, posts.update, posts.delete.
     *
     * @param array<string, string>|null $abilities способность => метод
     */
    public function resource(string $name, string $class, ?array $abilities = null): static
    {
        $abilities ??= [
            'viewAny' => 'viewAny',
            'view' => 'view',
            'create' => 'create',
            'update' => 'update',
            'delete' => 'delete',
        ];

        foreach ($abilities as $ability => $method) {
            $this->define($name . '.' . $ability, [$class, $method]);
        }

        return $this;
    }

    /**
     * @param class-string $model
     * @param class-string $policy
     */
    public function policy(string $model, string $policy): static
    {
        $this->policies[$model] = $policy;
        $this->policyClasses = [];

        return $this;
    }

    /** Выполняется ДО всего. Вернули не-null: ответ окончателен (true: разрешить всё, false: запретить всё). */
    public function before(Closure $callback): static
    {
        $this->before[] = $callback;

        return $this;
    }

    /** Выполняется после. Fn($user, string $ability, mixed $result, array $arguments). Может дать ответ, только если его нет. */
    public function after(Closure $callback): static
    {
        $this->after[] = $callback;

        return $this;
    }

    public function has(string ...$abilities): bool
    {
        foreach ($abilities as $ability) {
            if (!isset($this->abilities[$ability])) {
                return false;
            }
        }

        return $abilities !== [];
    }

    /**
     * Есть ли у модели политика (явная, #[UsePolicy] или по соглашению об имени).
     * Модули, которые защищают модель через Gate (ResourceAPI), без политики не вмешиваются.
     *
     * @param class-string|object $model
     */
    public function hasPolicy(string|object $model): bool
    {
        return $this->policyFor($model) !== null;
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return array_keys($this->abilities);
    }

    /** Gate, который отвечает от имени заданного пользователя (null: от имени гостя). */
    public function forUser(?Authenticatable $user): static
    {
        $clone = clone $this;
        $clone->userResolver = static fn (): ?Authenticatable => $user;

        return $clone;
    }

    // -------------------------------------------------------------- проверки

    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->inspect($ability, $arguments)->allowed();
    }

    public function denies(string $ability, mixed $arguments = []): bool
    {
        return !$this->allows($ability, $arguments);
    }

    /** Разрешены ВСЕ способности. @param iterable<string>|string $abilities */
    public function check(iterable|string $abilities, mixed $arguments = []): bool
    {
        foreach (is_string($abilities) ? [$abilities] : $abilities as $ability) {
            if (!$this->allows($ability, $arguments)) {
                return false;
            }
        }

        return true;
    }

    /** Разрешена ХОТЯ БЫ одна способность. @param iterable<string>|string $abilities */
    public function any(iterable|string $abilities, mixed $arguments = []): bool
    {
        foreach (is_string($abilities) ? [$abilities] : $abilities as $ability) {
            if ($this->allows($ability, $arguments)) {
                return true;
            }
        }

        return false;
    }

    /** Не разрешена НИ ОДНА способность. @param iterable<string>|string $abilities */
    public function none(iterable|string $abilities, mixed $arguments = []): bool
    {
        return !$this->any($abilities, $arguments);
    }

    /** @throws \Neo\Security\Authorization\AuthorizationException */
    public function authorize(string $ability, mixed $arguments = []): AuthorizationResponse
    {
        return $this->inspect($ability, $arguments)->authorize();
    }

    /** Полный ответ с причиной отказа и HTTP-статусом. */
    public function inspect(string $ability, mixed $arguments = []): AuthorizationResponse
    {
        $arguments = is_array($arguments) ? array_values($arguments) : [$arguments];
        $user = ($this->userResolver)();

        $result = null;

        foreach ($this->before as $callback) {
            if ($user === null && !$this->allowsGuests($callback)) {
                continue;
            }

            $result = $callback($user, $ability, $arguments);

            if ($result !== null) {
                break;
            }
        }

        $result ??= $this->decide($user, $ability, $arguments);

        foreach ($this->after as $callback) {
            if ($user === null && !$this->allowsGuests($callback)) {
                continue;
            }

            $afterResult = $callback($user, $ability, $result, $arguments);

            $result ??= $afterResult;
        }

        return $this->toResponse($result);
    }

    // ------------------------------------------------------------- внутреннее

    private function decide(?Authenticatable $user, string $ability, array $arguments): mixed
    {
        $policy = isset($arguments[0]) ? $this->policyFor($arguments[0]) : null;

        if ($policy !== null) {
            $method = $this->policyMethod($policy, $ability);

            if ($method !== null) {
                return $this->callPolicy($policy, $method, $user, $ability, $arguments);
            }
        }

        if (isset($this->abilities[$ability])) {
            $callable = $this->toCallable($this->abilities[$ability]);

            if ($user !== null || $this->allowsGuests($callable)) {
                return $callable($user, ...$arguments);
            }
        }

        return null;
    }

    private function callPolicy(object $policy, string $method, ?Authenticatable $user, string $ability, array $arguments): mixed
    {
        if (method_exists($policy, 'before') && ($user !== null || $this->allowsGuests([$policy, 'before']))) {
            $result = $policy->before($user, $ability, ...$arguments);

            if ($result !== null) {
                return $result;
            }
        }

        # Проверка на классе (`can('create', Post::class)`): строку-класс политике не передаём.
        if (is_string($arguments[0] ?? null)) {
            array_shift($arguments);
        }

        if ($user === null && !$this->allowsGuests([$policy, $method])) {
            return null;
        }

        return $policy->{$method}($user, ...$arguments);
    }

    /** Имя метода политики для способности: как есть или в camelCase (`view-any` => `viewAny`). */
    private function policyMethod(object $policy, string $ability): ?string
    {
        $candidates = [$ability, lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $ability))))];

        foreach ($candidates as $method) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) === 1 && is_callable([$policy, $method])) {
                return $method;
            }
        }

        return null;
    }

    /** Политика модели: явная регистрация, атрибут #[UsePolicy], соглашение об имени, политика родителя. */
    private function policyFor(mixed $subject): ?object
    {
        $class = is_object($subject) ? $subject::class : $subject;

        # Строка попадает сюда и из пользовательских данных: к автозагрузке допускаем только имя класса.
        if (!is_string($class) || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) !== 1) {
            return null;
        }

        if (!array_key_exists($class, $this->policyClasses)) {
            $this->policyClasses[$class] = class_exists($class) ? $this->findPolicyClass($class) : null;
        }

        $policyClass = $this->policyClasses[$class];

        return $policyClass === null ? null : $this->container->make($policyClass);
    }

    /** @return class-string|null */
    private function findPolicyClass(string $class): ?string
    {
        if (isset($this->policies[$class])) {
            return $this->policies[$class];
        }

        $attribute = (new ReflectionClass($class))->getAttributes(UsePolicy::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->policy;
        }

        $short = substr(strrchr('\\' . $class, '\\'), 1);
        $guess = $this->policiesNamespace . $short . 'Policy';

        if (class_exists($guess)) {
            return $guess;
        }

        foreach ($this->policies as $model => $policy) {
            if (is_subclass_of($class, $model)) {
                return $policy;
            }
        }

        return null;
    }

    /** @return callable */
    private function toCallable(Closure|array|string $callback): callable
    {
        if ($callback instanceof Closure) {
            return $callback;
        }

        if (is_string($callback)) {
            $callback = str_contains($callback, '@') ? explode('@', $callback, 2) : [$callback, '__invoke'];
        }

        [$target, $method] = $callback;

        if (!is_object($target)) {
            $target = $this->container->make((string) $target);
        }

        if (!is_callable([$target, $method])) {
            throw new InvalidArgumentException('Способность Gate указывает на несуществующий метод ' . $target::class . "::{$method}().");
        }

        return [$target, $method];
    }

    /** Допускает ли обработчик гостя: тип первого аргумента nullable (`?User $user`) или `= null`. */
    private function allowsGuests(callable|array $callback): bool
    {
        $reflection = $callback instanceof Closure
            ? new ReflectionFunction($callback)
            : new ReflectionMethod($callback[0], $callback[1]);

        $parameter = $reflection->getParameters()[0] ?? null;

        if ($parameter === null) {
            return false;
        }

        return ($parameter->hasType() && $parameter->allowsNull())
            || ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null);
    }

    private function toResponse(mixed $result): AuthorizationResponse
    {
        return match (true) {
            $result instanceof AuthorizationResponse => $result,
            (bool) $result => AuthorizationResponse::allow(),
            default => AuthorizationResponse::deny(),
        };
    }
}
