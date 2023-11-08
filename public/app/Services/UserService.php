<?php

declare(strict_types=1);

namespace App\App\Services;

use App\Neo\Database\DB;

class UserService
{
    public static function deleteUser(string $userId): void
    {
        DB::table('users')
            ->where('id', '=', $userId)
            ->delete();
    }

    public static function updateUser(object $user): void
    {
        DB::table('users')
            ->where('id', '=', $user->id)
            ->update([
                'name' => $user->name,
                'email' => $user->email
            ]);
    }

    public static function createUser(object $user): void
    {
        DB::table('users')->insert([
            'name' => $user->name,
            'email' => $user->email
        ]);
    }

    public static function editUser(string $userId): object
    {
        return DB::table('users')
            ->where('id', '=', $userId)
            ->first();
    }

    public static function getUser(string $userId): object
    {
        return DB::table('users')
            ->where('id', '=', $userId)
            ->first();
    }
}
