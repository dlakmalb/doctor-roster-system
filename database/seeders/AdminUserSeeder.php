<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the local administrator account.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('The admin user seeder may only run in local or testing environments.');
        }

        User::query()->firstOrCreate(
            ['email' => 'admin@roster.test'],
            [
                'name' => 'Admin',
                'password' => Hash::make('12345678'),
            ],
        );
    }
}
