<?php

declare(strict_types=1);

use Neo\Security\Authentication\AuthManager;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authentication\Contracts\Guard;
use Neo\Security\Authorization\Gate\GateManager;

/** `auth()` менеджер, `auth('api')` охранник: `auth()->user()`, `auth()->check()`, `auth()->attempt([...])`. */
if (!function_exists('auth')) {
    function auth(?string $guard = null): AuthManager|Guard
    {
        $manager = kernel(AuthManager::class);

        return $guard === null ? $manager : $manager->guard($guard);
    }
}

/** Текущий пользователь (охранник по умолчанию) или null. */
if (!function_exists('user')) {
    function user(?string $guard = null): ?Authenticatable
    {
        return kernel(AuthManager::class)->guard($guard)->user();
    }
}

/** `gate()` Gate, `gate('update', $post)` ответ как у authorize(): бросает 403. */
if (!function_exists('gate')) {
    function gate(?string $ability = null, mixed $arguments = []): GateManager|\Neo\Security\Authorization\Gate\AuthorizationResponse
    {
        $gate = kernel(GateManager::class);

        return $ability === null ? $gate : $gate->authorize($ability, $arguments);
    }
}

/** `can('post.update')`, `can('update', $post)`: разрешено ли текущему пользователю (для шаблонов: `@if (can('x'))`). */
if (!function_exists('can')) {
    function can(string $ability, mixed $arguments = []): bool
    {
        return kernel(GateManager::class)->allows($ability, $arguments);
    }
}

if (!function_exists('cannot')) {
    function cannot(string $ability, mixed $arguments = []): bool
    {
        return kernel(GateManager::class)->denies($ability, $arguments);
    }
}
