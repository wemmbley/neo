<?php

declare(strict_types=1);

namespace App\Neo\Database;

use App\Neo\Helpers\FileSystem\Path;

class Seeder
{
    public static function seed(int $count): void
    {
        $seedersPath = Path::abs('app/Database/Seeders/');
        $seederFiles = Path::scan($seedersPath);

        foreach ($seederFiles as $seederFile) {
            $seeder = require_once $seedersPath . $seederFile;
            $seeder = new $seeder();

            $i = 0;

            do {
                $i++;
                $seeder->run();
            } while ($i < $count);
        }
    }
}