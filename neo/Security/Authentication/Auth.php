<?php

declare(strict_types=1);

namespace Neo\Security\Authentication;

use Closure;
use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authentication\Contracts\Guard;
use Neo\Security\Authentication\Contracts\UserProvider;

/**
 * @method static Guard guard(?string $name = null)
 * @method static AuthManager viaRequest(string $guard, Closure $callback)
 * @method static UserProvider provider(?string $name = null)
 * @method static string defaultGuard()
 * @method static bool check()
 * @method static bool guest()
 * @method static Authenticatable|null user()
 * @method static int|string|null id()
 * @method static bool attempt(array $credentials = [])
 * @method static bool validate(array $credentials = [])
 * @method static void login(Authenticatable $user)
 * @method static Authenticatable|null loginUsingId(int|string $id)
 * @method static void logout()
 * @method static void setUser(Authenticatable $user)
 *
 * @see AuthManager
 */
#[FacadeOf(AuthManager::class)]
final class Auth extends Facade {}
