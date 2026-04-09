<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@bitunix.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => 'admin',
            ],
        );
    }
}
