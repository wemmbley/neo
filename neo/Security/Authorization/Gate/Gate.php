<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Gate;

use Closure;
use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Security\Authentication\Contracts\Authenticatable;

/**
 * @method static GateManager define(string $ability, Closure|array|string $callback)
 * @method static GateManager resource(string $name, string $class, ?array $abilities = null)
 * @method static GateManager policy(string $model, string $policy)
 * @method static GateManager before(Closure $callback)
 * @method static GateManager after(Closure $callback)
 * @method static GateManager forUser(?Authenticatable $user)
 * @method static bool has(string ...$abilities)
 * @method static list<string> abilities()
 * @method static bool allows(string $ability, mixed $arguments = [])
 * @method static bool denies(string $ability, mixed $arguments = [])
 * @method static bool check(iterable|string $abilities, mixed $arguments = [])
 * @method static bool any(iterable|string $abilities, mixed $arguments = [])
 * @method static bool none(iterable|string $abilities, mixed $arguments = [])
 * @method static AuthorizationResponse authorize(string $ability, mixed $arguments = [])
 * @method static AuthorizationResponse inspect(string $ability, mixed $arguments = [])
 *
 * @see GateManager
 */
#[FacadeOf(GateManager::class)]
final class Gate extends Facade {}
