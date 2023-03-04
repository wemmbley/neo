<?php

declare(strict_types=1);

namespace App\Neo\Database;

class Schema
{
    public static function create(string $tableName, \Closure $closure)
    {
        $blueprint = new Blueprint($tableName);

        call_user_func_array($closure, [$blueprint]);

        $createTableSql = $blueprint->get();

        DB::query($createTableSql);
    }

    public static function drop(string $tableName)
    {
        DB::query('DROP TABLE ' . $tableName);
    }
}