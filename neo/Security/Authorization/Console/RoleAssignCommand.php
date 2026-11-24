<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Security\Authentication\AuthManager;
use Neo\Security\Authorization\Exceptions\RoleDoesNotExist;

class RoleAssignCommand extends Command
{
    protected string $signature = 'role:assign {email : Email пользователя} {role : Имя роли}';

    protected string $description = 'Назначает роль пользователю (так создаётся первый администратор)';

    public function handle(): int
    {
        $user = kernel(AuthManager::class)->provider()->retrieveByCredentials(['email' => (string) $this->argument('email')]);

        if ($user === null || !method_exists($user, 'assignRole')) {
            $this->error('Пользователь не найден или его модель не использует HasRoles.');

            return self::FAILURE;
        }

        try {
            $user->assignRole((string) $this->argument('role'));
        } catch (RoleDoesNotExist $e) {
            $this->error($e->getMessage() . ' Создайте её: php cli role:create ' . $this->argument('role'));

            return self::FAILURE;
        }

        $this->info('Роль назначена. Роли пользователя: ' . implode(', ', $user->getRoleNames()));

        return self::SUCCESS;
    }
}
