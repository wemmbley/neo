<?php

declare(strict_types=1);

namespace App\Neo\Database;

use App\Neo\Helpers\Primitives\Arr;
use PDO;

class DB
{
    protected static PDO $pdo;

    protected static string $table = '';
    protected static string $where = '';

    public static function boot()
    {
        $connector = new Connector(env('DB_CONNECTION'));

        static::$pdo = $connector
            ->withHost(env('DB_HOST'))
            ->withPassword(env('DB_PASSWORD'))
            ->withUser(env('DB_USERNAME'))
            ->withDatabase(env('DB_DATABASE'))
            ->withPort(env('DB_PORT'))
            ->get();
    }

    public static function table(string $table): DB
    {
        static::$table = $table;

        return new static;
    }

    public static function delete(): void
    {
        $sql = 'DELETE FROM ' . static::$table . ' ' . static::$where;

        DB::query($sql);
    }

    public static function insert(array $fields): void
    {
        $sqlStart = 'INSERT INTO ' . static::$table . ' (';
        $sqlMiddle = ' VALUES (';

        foreach ($fields as $fieldName => $fieldValue) {
            $sqlStart .= $fieldName;
            $sqlMiddle .= '\'' . $fieldValue . '\'';

            if ($fieldName !== Arr::lastKey($fields)) {
                $sqlStart .= ', ';
                $sqlMiddle .= ', ';
            } else {
                $sqlStart .= ') ';
                $sqlMiddle .= ');';
            }
        }

        DB::query($sqlStart . $sqlMiddle);
    }

    public static function update(array $fields): void
    {
        $sql = 'UPDATE ' . static::$table . ' SET ';

        foreach ($fields as $fieldName => $fieldValue) {
            $sql .= $fieldName . ' = \'' . $fieldValue . '\'';

            if ($fieldName !== Arr::lastKey($fields))
                $sql .= ', ';
        }

        $sql = $sql . static::$where;

        DB::query($sql);
    }

    public static function where(string $param, string $equal, string $param2): DB
    {
        static::$where .= ' WHERE ' . $param . $equal . $param2;

        return new static;
    }

    public static function get(): array
    {
        $sql = 'SELECT * FROM ' . static::$table . static::$where;

        return static::query($sql);
    }

    public static function first()
    {
        $sql = 'SELECT * FROM ' . static::$table . static::$where . ' LIMIT 1';

        return static::query($sql)[0];
    }

    public static function query(string $sql): array
    {
        return static::$pdo->query($sql)->fetchAll(config('database.fetch'));
    }
}