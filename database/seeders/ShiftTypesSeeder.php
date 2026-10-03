<?php

namespace Database\Seeders;

use App\Models\ShiftType;
use Illuminate\Database\Seeder;

class ShiftTypesSeeder extends Seeder
{
    public function run(): void
    {
        $shiftTypes = [
            ['code' => 'weekday_day', 'name' => 'Weekday Day', 'start_time' => '08:00:00', 'end_time' => '14:00:00', 'duration_minutes' => 360, 'main_count' => 4, 'optional_count' => 2, 'is_overnight' => false],
            ['code' => 'weekday_evening', 'name' => 'Weekday Evening', 'start_time' => '14:00:00', 'end_time' => '20:00:00', 'duration_minutes' => 360, 'main_count' => 3, 'optional_count' => 1, 'is_overnight' => false],
            ['code' => 'weekday_night', 'name' => 'Weekday Night', 'start_time' => '20:00:00', 'end_time' => '08:00:00', 'duration_minutes' => 720, 'main_count' => 2, 'optional_count' => 0, 'is_overnight' => true],
            ['code' => 'weekend_day', 'name' => 'Weekend Day', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'duration_minutes' => 480, 'main_count' => 3, 'optional_count' => 3, 'is_overnight' => false],
            ['code' => 'weekend_night', 'name' => 'Weekend Night', 'start_time' => '16:00:00', 'end_time' => '08:00:00', 'duration_minutes' => 960, 'main_count' => 2, 'optional_count' => 0, 'is_overnight' => true],
        ];

        foreach ($shiftTypes as $shiftType) {
            ShiftType::firstOrCreate(
                ['code' => $shiftType['code']],
                [...$shiftType, 'is_active' => true],
            );
        }
    }
}
