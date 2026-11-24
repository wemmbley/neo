<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Concerns;

use Closure;
use LogicException;
use Neo\Information\Persistence\ORM\Model;
use Neo\Security\Authorization\Permission\Permission;
use Neo\Security\Authorization\Permission\Pivot;
use Neo\Security\Authorization\Permission\PermissionRegistrar;
use Neo\Security\Authorization\Role\Role;

/**
 * Роли и права для модели пользователя (в духе spatie/laravel-permission).
 *
 *   class User extends Model implements Authenticatable
 *   {
 *       use AuthenticatableTrait, Authorizable, HasRoles;
 *   }
 *
 *   $user->assignRole('admin');
 *   $user->givePermissionTo('post.update');          // право напрямую, мимо роли
 *   $user->hasRole('admin|editor');                  // любая из перечисленных
 *   $user->hasPermissionTo('post.read');             // и прямое, и через роли; `post.*` покрывает `post.read`
 *
 * Роли и права читаются из БД один раз на экземпляр модели. Любая правка через этот
 * трейт, Role или Permission сбрасывает кеш (см. PermissionRegistrar::forgetCachedPermissions()).
 * Прямые правки таблиц в обход моделей кеш НЕ сбрасывают: вызовите
 * `PermissionRegistrar::forgetCachedPermissions()`.
 */
trait HasRoles
{
    /**
     * Приватное объявленное свойство: ORM видит только публичные, а присвоение
     * необъявленного свойства превратилось бы в «динамический атрибут» и попало бы в БД.
     *
     * @var array<string, mixed>
     */
    private array $authzCache = [];

    /** Охранник, для которого действуют роли и права пользователя. */
    public function getGuardName(): string
    {
        return PermissionRegistrar::defaultGuard();
    }

    // ------------------------------------------------------------------ роли

    /** @return list<string> */
    public function getRoleNames(): array
    {
        return $this->authzRemember('roles', function (): array {
            $roles = PermissionRegistrar::table('roles');
            $pivot = PermissionRegistrar::table('user_roles');

            return array_values(array_unique($this->getConnection()->table($roles)
                ->select($roles . '.name')
                ->join($pivot, $roles . '.id', '=', $pivot . '.role_id')
                ->where($pivot . '.' . PermissionRegistrar::userKey(), '=', $this->getKey())
                ->where($roles . '.guard_name', '=', $this->getGuardName())
                ->pluck('name')));
        });
    }

    /** Есть ли ХОТЯ БЫ одна из ролей: `hasRole('admin')`, `hasRole('admin|editor')`, `hasRole(['a', 'b'])`. */
    public function hasRole(string|Model|array $roles): bool
    {
        $names = PermissionRegistrar::names($roles);

        return $names !== [] && array_intersect($names, $this->getRoleNames()) !== [];
    }

    public function hasAnyRole(string|Model|array ...$roles): bool
    {
        return $this->hasRole($roles);
    }

    /** Есть ли ВСЕ перечисленные роли. */
    public function hasAllRoles(string|Model|array ...$roles): bool
    {
        $names = PermissionRegistrar::names($roles);

        return $names !== [] && array_diff($names, $this->getRoleNames()) === [];
    }

    /** Ролей РОВНО столько и именно таких. */
    public function hasExactRoles(string|Model|array ...$roles): bool
    {
        $names = array_unique(PermissionRegistrar::names($roles));
        $own = $this->getRoleNames();

        return count($names) === count($own) && array_diff($names, $own) === [];
    }

    public function isSuperAdmin(): bool
    {
        $role = PermissionRegistrar::superAdminRole();

        return $role !== null && $this->hasRole($role);
    }

    /**
     * Назначить роли: имена, id, модели Role или массивы. Роли должны существовать.
     *
     * @return $this
     */
    public function assignRole(string|int|Role|array ...$roles): static
    {
        Pivot::attach(
            $this->getConnection(),
            PermissionRegistrar::table('user_roles'),
            PermissionRegistrar::userKey(),
            $this->persistedKey(),
            'role_id',
            PermissionRegistrar::roleIds($roles, $this->getGuardName()),
        );

        return $this->flushAuthorizationCache();
    }

    /** @return $this */
    public function removeRole(string|int|Role|array ...$roles): static
    {
        Pivot::detach(
            $this->getConnection(),
            PermissionRegistrar::table('user_roles'),
            PermissionRegistrar::userKey(),
            $this->persistedKey(),
            'role_id',
            PermissionRegistrar::roleIds($roles, $this->getGuardName()),
        );

        return $this->flushAuthorizationCache();
    }

    /** Оставляет пользователю РОВНО перечисленные роли. @return $this */
    public function syncRoles(string|int|Role|array ...$roles): static
    {
        Pivot::sync(
            $this->getConnection(),
            PermissionRegistrar::table('user_roles'),
            PermissionRegistrar::userKey(),
            $this->persistedKey(),
            'role_id',
            PermissionRegistrar::roleIds($roles, $this->getGuardName()),
        );

        return $this->flushAuthorizationCache();
    }

    // ----------------------------------------------------------------- права

    /** Права, выданные пользователю напрямую. @return list<string> */
    public function getDirectPermissionNames(): array
    {
        return $this->authzRemember('direct', function (): array {
            $permissions = PermissionRegistrar::table('permissions');
            $pivot = PermissionRegistrar::table('user_permissions');

            return array_values(array_unique($this->getConnection()->table($permissions)
                ->select($permissions . '.name')
                ->join($pivot, $permissions . '.id', '=', $pivot . '.permission_id')
                ->where($pivot . '.' . PermissionRegistrar::userKey(), '=', $this->getKey())
                ->where($permissions . '.guard_name', '=', $this->getGuardName())
                ->pluck('name')));
        });
    }

    /** Права, пришедшие через роли. @return list<string> */
    public function getPermissionNamesViaRoles(): array
    {
        return $this->authzRemember('viaRoles', function (): array {
            $permissions = PermissionRegistrar::table('permissions');
            $rolePermissions = PermissionRegistrar::table('role_permissions');
            $userRoles = PermissionRegistrar::table('user_roles');

            return array_values(array_unique($this->getConnection()->table($permissions)
                ->select($permissions . '.name')
                ->join($rolePermissions, $permissions . '.id', '=', $rolePermissions . '.permission_id')
                ->join($userRoles, $rolePermissions . '.role_id', '=', $userRoles . '.role_id')
                ->where($userRoles . '.' . PermissionRegistrar::userKey(), '=', $this->getKey())
                ->where($permissions . '.guard_name', '=', $this->getGuardName())
                ->pluck('name')));
        });
    }

    /** Все права пользователя: прямые и через роли (как выданы, с `*`). @return list<string> */
    public function getPermissionNames(): array
    {
        return array_values(array_unique([...$this->getDirectPermissionNames(), ...$this->getPermissionNamesViaRoles()]));
    }

    /** Есть ли право (прямое или через роль, с учётом `post.*`). */
    public function hasPermissionTo(string|Model $permission): bool
    {
        return PermissionRegistrar::anyGrants($this->getPermissionNames(), $this->permissionName($permission));
    }

    /** Синоним hasPermissionTo(): имя из spatie. */
    public function checkPermissionTo(string|Model $permission): bool
    {
        return $this->hasPermissionTo($permission);
    }

    /** Есть ли ХОТЯ БЫ одно из прав (`'a|b'` и массивы тоже подходят). */
    public function hasAnyPermission(string|Model|array ...$permissions): bool
    {
        foreach (PermissionRegistrar::names($permissions) as $name) {
            if ($this->hasPermissionTo($name)) {
                return true;
            }
        }

        return false;
    }

    /** Есть ли ВСЕ перечисленные права. */
    public function hasAllPermissions(string|Model|array ...$permissions): bool
    {
        $names = PermissionRegistrar::names($permissions);

        foreach ($names as $name) {
            if (!$this->hasPermissionTo($name)) {
                return false;
            }
        }

        return $names !== [];
    }

    public function hasDirectPermission(string|Model $permission): bool
    {
        return PermissionRegistrar::anyGrants($this->getDirectPermissionNames(), $this->permissionName($permission));
    }

    public function hasPermissionViaRole(string|Model $permission): bool
    {
        return PermissionRegistrar::anyGrants($this->getPermissionNamesViaRoles(), $this->permissionName($permission));
    }

    /**
     * Выдать право напрямую. Право должно существовать.
     *
     * @return $this
     */
    public function givePermissionTo(string|int|Permission|array ...$permissions): static
    {
        Pivot::attach(
            $this->getConnection(),
            PermissionRegistrar::table('user_permissions'),
            PermissionRegistrar::userKey(),
            $this->persistedKey(),
            'permission_id',
            PermissionRegistrar::permissionIds($permissions, $this->getGuardName()),
        );

        return $this->flushAuthorizationCache();
    }

    /** @return $this */
    public function revokePermissionTo(string|int|Permission|array ...$permissions): static
    {
        Pivot::detach(
            $this->getConnection(),
            PermissionRegistrar::table('user_permissions'),
            PermissionRegistrar::userKey(),
            $this->persistedKey(),
            'permission_id',
            PermissionRegistrar::permissionIds($permissions, $this->getGuardName()),
        );

        return $this->flushAuthorizationCache();
    }

    /** Оставляет пользователю РОВНО перечисленные прямые права (права ролей не трогает). @return $this */
    public function syncPermissions(string|int|Permission|array ...$permissions): static
    {
        Pivot::sync(
            $this->getConnection(),
            PermissionRegistrar::table('user_permissions'),
            PermissionRegistrar::userKey(),
            $this->persistedKey(),
            'permission_id',
            PermissionRegistrar::permissionIds($permissions, $this->getGuardName()),
        );

        return $this->flushAuthorizationCache();
    }

    // -------------------------------------------------------------- служебное

    /** Забыть закешированные роли и права этого и всех уже загруженных пользователей. @return $this */
    public function flushAuthorizationCache(): static
    {
        $this->authzCache = [];
        PermissionRegistrar::forgetCachedPermissions();

        return $this;
    }

    private function permissionName(string|Model $permission): string
    {
        return $permission instanceof Model ? (string) $permission->getAttribute('name') : $permission;
    }

    private function persistedKey(): int|string
    {
        if (!$this->exists || $this->getKey() === null) {
            throw new LogicException('Роли и права можно менять только у сохранённого пользователя: сначала вызовите save().');
        }

        return $this->getKey();
    }

    private function authzRemember(string $key, Closure $load): mixed
    {
        if (($this->authzCache['_version'] ?? null) !== PermissionRegistrar::version()) {
            $this->authzCache = ['_version' => PermissionRegistrar::version()];
        }

        return $this->authzCache[$key] ??= $load();
    }
}
