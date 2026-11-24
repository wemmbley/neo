<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Role;

use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Model;
use Neo\Security\Authorization\Exceptions\RoleDoesNotExist;
use Neo\Security\Authorization\Permission\Pivot;
use Neo\Security\Authorization\Permission\Permission;
use Neo\Security\Authorization\Permission\PermissionRegistrar;

/**
 * Роль: именованный набор прав (`admin`, `moderator`). Таблица: roles.
 * Выдача прав роли: `$role->givePermissionTo('post.update', 'post.delete')`.
 */
class Role extends Model
{
    #[PrimaryKey]
    public int $id;

    public string $name;

    public string $guard_name = 'web';

    public static function findByName(string $name, ?string $guard = null): ?static
    {
        return static::query()
            ->where('name', '=', $name)
            ->where('guard_name', '=', $guard ?? PermissionRegistrar::defaultGuard())
            ->first();
    }

    public static function findByNameOrFail(string $name, ?string $guard = null): static
    {
        $guard ??= PermissionRegistrar::defaultGuard();

        return static::findByName($name, $guard) ?? throw RoleDoesNotExist::named($name, $guard);
    }

    public static function findOrCreate(string $name, ?string $guard = null): static
    {
        $guard ??= PermissionRegistrar::defaultGuard();

        return static::findByName($name, $guard) ?? static::create(['name' => $name, 'guard_name' => $guard]);
    }

    /**
     * Выдать роли права: имена, id, модели Permission или массивы из них. Права должны существовать.
     *
     * @return $this
     */
    public function givePermissionTo(string|int|Permission|array ...$permissions): static
    {
        Pivot::attach(
            $this->getConnection(),
            PermissionRegistrar::table('role_permissions'),
            'role_id',
            $this->getKey(),
            'permission_id',
            PermissionRegistrar::permissionIds($permissions, $this->guard_name),
        );

        PermissionRegistrar::forgetCachedPermissions();

        return $this;
    }

    /** @return $this */
    public function revokePermissionTo(string|int|Permission|array ...$permissions): static
    {
        Pivot::detach(
            $this->getConnection(),
            PermissionRegistrar::table('role_permissions'),
            'role_id',
            $this->getKey(),
            'permission_id',
            PermissionRegistrar::permissionIds($permissions, $this->guard_name),
        );

        PermissionRegistrar::forgetCachedPermissions();

        return $this;
    }

    /** Оставляет роли РОВНО перечисленные права. @return $this */
    public function syncPermissions(string|int|Permission|array ...$permissions): static
    {
        Pivot::sync(
            $this->getConnection(),
            PermissionRegistrar::table('role_permissions'),
            'role_id',
            $this->getKey(),
            'permission_id',
            PermissionRegistrar::permissionIds($permissions, $this->guard_name),
        );

        PermissionRegistrar::forgetCachedPermissions();

        return $this;
    }

    /** Имена прав роли (как выданы, с `*`). @return list<string> */
    public function permissionNames(): array
    {
        $permissions = PermissionRegistrar::table('permissions');
        $pivot = PermissionRegistrar::table('role_permissions');

        return array_values($this->getConnection()->table($permissions)
            ->select($permissions . '.name')
            ->join($pivot, $permissions . '.id', '=', $pivot . '.permission_id')
            ->where($pivot . '.role_id', '=', $this->getKey())
            ->pluck('name'));
    }

    /** Есть ли у роли право (с учётом подстановочных знаков). */
    public function hasPermissionTo(string|Permission $permission): bool
    {
        $requested = $permission instanceof Permission ? $permission->name : $permission;

        return PermissionRegistrar::anyGrants($this->permissionNames(), $requested);
    }

    public function save(): bool
    {
        $saved = parent::save();
        PermissionRegistrar::forgetCachedPermissions();

        return $saved;
    }

    public function delete(): bool
    {
        $deleted = parent::delete();
        PermissionRegistrar::forgetCachedPermissions();

        return $deleted;
    }
}
