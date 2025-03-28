<?php

use App\Neo\Database\DB;
use App\Neo\Helpers\Faker;

return new class
{
    /**
     * Run the seeder.
     *
     * @return void
     */
    public static function run(): void
    {
        DB::table('users')->insert([
            'name' => Faker::name(),
            'email' => Faker::randomText(10) . '@gmail.com',
            'password' => Faker::password(),
       ]);
    }
};