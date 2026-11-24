<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Security\Authorization\Permission\PermissionRegistrar;

class PermissionCreateCommand extends Command
{
    protected string $signature = 'permission:create {name : Имя права, например post.update} {--role= : Сразу выдать это право роли} {--guard= : Охранник}';

    protected string $description = 'Создаёт право (если его ещё нет)';

    public function handle(): int
    {
        $guard = $this->option('guard') ?: null;
        $permission = PermissionRegistrar::permissionModel()::findOrCreate((string) $this->argument('name'), $guard);

        $this->info("Право [{$permission->name}] для охранника [{$permission->guard_name}] готово.");

        if ($roleName = $this->option('role')) {
            $role = PermissionRegistrar::roleModel()::findByName((string) $roleName, $guard);

            if ($role === null) {
                $this->error("Роль [{$roleName}] не найдена.");

                return self::FAILURE;
            }

            $role->givePermissionTo($permission);
            $this->info("Выдано роли [{$role->name}].");
        }

        return self::SUCCESS;
    }
}
