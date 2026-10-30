<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DoctorsSeeder::class,
            ShiftTypesSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            $this->call([
                AdminUserSeeder::class,
                September2026HistoricalBaselineSeeder::class,
                October2026DoctorRequestsSeeder::class,
                October2026WeekendRotationSeeder::class,
            ]);
        }
    }
}
