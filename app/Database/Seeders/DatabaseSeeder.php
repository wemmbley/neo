<?php

namespace App\Database\Seeders;

use App\Database\Models\User;
use Neo\Information\Persistence\Database\Seeding\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory(10)->create();
    }
}