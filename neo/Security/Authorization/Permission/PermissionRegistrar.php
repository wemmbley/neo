<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Permission;

use Neo\Information\Persistence\ORM\Model;
use Neo\Security\Authorization\Exceptions\PermissionDoesNotExist;
use Neo\Security\Authorization\Exceptions\RoleDoesNotExist;
use Neo\Security\Authorization\Role\Role;
use Traversable;

/**
 * Настройки авторизации (app/Config/Auth.php, секция `authorization`), сопоставление прав
 * с подстановочными знаками и приведение «роль или право в любом виде» к id.
 *
 * Состояния запроса не хранит. Единственное изменяемое поле, версия, нужно моделям пользователей:
 * любая правка ролей/прав в процессе сбрасывает их внутренний кеш.
 */
final class PermissionRegistrar
{
    private const array TABLES = [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'role_permissions' => 'role_permissions',
        'user_roles' => 'user_roles',
        'user_permissions' => 'user_permissions',
    ];

    private static int $version = 0;

    public static function version(): int
    {
        return self::$version;
    }

    /** Сбрасывает кеш ролей и прав у уже загруженных пользователей. */
    public static function forgetCachedPermissions(): void
    {
        self::$version++;
    }

    public static function table(string $key): string
    {
        return (string) config('auth.authorization.tables.' . $key, self::TABLES[$key]);
    }

    /** Колонка пивот-таблиц user_roles / user_permissions, указывающая на пользователя. */
    public static function userKey(): string
    {
        return (string) config('auth.authorization.user_key', 'user_id');
    }

    public static function defaultGuard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    /** @return class-string<Role> */
    public static function roleModel(): string
    {
        return (string) config('auth.authorization.models.role', Role::class);
    }

    /** @return class-string<Permission> */
    public static function permissionModel(): string
    {
        return (string) config('auth.authorization.models.permission', Permission::class);
    }

    public static function wildcards(): bool
    {
        return (bool) config('auth.authorization.wildcards', true);
    }

    /** Роль, которой разрешено всё (Gate::before). null: выключено. */
    public static function superAdminRole(): ?string
    {
        $role = config('auth.authorization.super_admin_role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * Покрывает ли выданное право запрошенное: `post.*` покрывает `post.read`, `*` покрывает всё.
     * Подстановочные знаки работают только у ВЫДАННОГО права, запрошенное всегда конкретное.
     */
    public static function grants(string $granted, string $requested): bool
    {
        if ($granted === $requested) {
            return true;
        }

        if (!self::wildcards()) {
            return false;
        }

        if ($granted === '*') {
            return true;
        }

        return str_ends_with($granted, '.*') && str_starts_with($requested, substr($granted, 0, -1));
    }

    /** @param iterable<string> $granted */
    public static function anyGrants(iterable $granted, string $requested): bool
    {
        foreach ($granted as $name) {
            if (self::grants($name, $requested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Роли в любом виде (имя, id, модель, массивы) в список id. Несуществующая роль: исключение,
     * чтобы опечатка не превратилась в молча не выданный доступ.
     *
     * @return list<int|string>
     */
    public static function roleIds(iterable $items, string $guard): array
    {
        $model = self::roleModel();
        $ids = [];

        foreach (self::flatten($items) as $item) {
            $ids[] = match (true) {
                $item instanceof Model => $item->getKey(),
                is_int($item) => ($model::find($item) ?? throw RoleDoesNotExist::withId($item))->getKey(),
                default => ($model::findByName((string) $item, $guard) ?? throw RoleDoesNotExist::named((string) $item, $guard))->getKey(),
            };
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int|string> */
    public static function permissionIds(iterable $items, string $guard): array
    {
        $model = self::permissionModel();
        $ids = [];

        foreach (self::flatten($items) as $item) {
            $ids[] = match (true) {
                $item instanceof Model => $item->getKey(),
                is_int($item) => ($model::find($item) ?? throw PermissionDoesNotExist::withId($item))->getKey(),
                default => ($model::findByName((string) $item, $guard) ?? throw PermissionDoesNotExist::named((string) $item, $guard))->getKey(),
            };
        }

        return array_values(array_unique($ids));
    }

    /**
     * Имена из `'a|b'`, массивов и моделей: для hasRole()/hasPermissionTo() и middleware.
     *
     * @return list<string>
     */
    public static function names(mixed $items): array
    {
        $names = [];

        foreach (self::flatten(is_iterable($items) ? $items : [$items]) as $item) {
            if ($item instanceof Model) {
                $names[] = (string) $item->getAttribute('name');
                continue;
            }

            foreach (explode('|', (string) $item) as $part) {
                if (($part = trim($part)) !== '') {
                    $names[] = $part;
                }
            }
        }

        return $names;
    }

    /** @return list<mixed> */
    private static function flatten(iterable $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            if (is_array($item) || $item instanceof Traversable) {
                array_push($flat, ...self::flatten($item));
                continue;
            }

            $flat[] = $item;
        }

        return $flat;
    }
}
