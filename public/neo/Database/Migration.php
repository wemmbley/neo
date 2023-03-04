<?php

declare(strict_types=1);

namespace App\Neo\Database;

use App\Neo\Helpers\FileSystem\Path;

class Migration
{
    public static function migrate()
    {
        static::action('migrate');
    }

    public static function rollback()
    {
        static::action('rollback');
    }

    protected static function action(string $action)
    {
        $migrationsPath = Path::abs('app/Database/Migrations/');
        $migrationFiles = Path::scan($migrationsPath);

        foreach ($migrationFiles as $migrationFile) {
            $migration = require_once $migrationsPath . $migrationFile;
            $migration = new $migration();

            switch($action) {
                case 'migrate': {
                    $migration->up();
                    break;
                }
                case 'rollback': {
                    $migration->down();
                    break;
                }
            }

        }
    }
}