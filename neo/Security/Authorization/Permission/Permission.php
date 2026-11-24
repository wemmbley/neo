<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Permission;

use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Model;
use Neo\Security\Authorization\Exceptions\PermissionDoesNotExist;

/**
 * Право на ОДНО действие: `post.read`, `post.update`, `user.ban`.
 * Подстановочные знаки: `post.*` (всё про посты), `*` (вообще всё), если включены в app/Config/Auth.php.
 *
 * Имя уникально в пределах охранника (guard_name). Таблица: permissions.
 */
class Permission extends Model
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

        return static::findByName($name, $guard) ?? throw PermissionDoesNotExist::named($name, $guard);
    }

    public static function findOrCreate(string $name, ?string $guard = null): static
    {
        $guard ??= PermissionRegistrar::defaultGuard();

        return static::findByName($name, $guard) ?? static::create(['name' => $name, 'guard_name' => $guard]);
    }

    /** Имена ролей, которым выдано это право. @return list<string> */
    public function roleNames(): array
    {
        $roles = PermissionRegistrar::table('roles');
        $pivot = PermissionRegistrar::table('role_permissions');

        return array_values($this->getConnection()->table($roles)
            ->select($roles . '.name')
            ->join($pivot, $roles . '.id', '=', $pivot . '.role_id')
            ->where($pivot . '.permission_id', '=', $this->getKey())
            ->pluck('name'));
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
