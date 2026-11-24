<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Security\Authorization\Permission\PermissionRegistrar;

class RoleCreateCommand extends Command
{
    protected string $signature = 'role:create {name : Имя роли} {--guard= : Охранник (по умолчанию из auth.defaults.guard)}';

    protected string $description = 'Создаёт роль (если её ещё нет)';

    public function handle(): int
    {
        $role = PermissionRegistrar::roleModel()::findOrCreate((string) $this->argument('name'), $this->option('guard') ?: null);

        $this->info("Роль [{$role->name}] для охранника [{$role->guard_name}] готова.");

        return self::SUCCESS;
    }
}
