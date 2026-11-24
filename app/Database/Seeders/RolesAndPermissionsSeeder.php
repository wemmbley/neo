<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use Neo\Information\Persistence\Database\Seeding\Seeder;
use Neo\Security\Authorization\Permission\Permission;
use Neo\Security\Authorization\Role\Role;

/** Базовые роли и права: `admin` (всё про пользователей) и `user`. Идемпотентен. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['user.read', 'user.update', 'user.delete'] as $name) {
            Permission::findOrCreate($name);
        }

        Role::findOrCreate('user');
        Role::findOrCreate('admin')
            ->givePermissionTo(
                'user.read', 'user.update', 'user.delete'
            );
    }
}
