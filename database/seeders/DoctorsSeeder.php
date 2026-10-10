<?php

namespace Database\Seeders;

use App\Models\Doctor;
use Illuminate\Database\Seeder;

class DoctorsSeeder extends Seeder
{
    public function run(): void
    {
        $doctors = [
            ['short_code' => 'N', 'name' => 'Dr Nuwan (MOIC)'],
            ['short_code' => 'S', 'name' => 'Dr Sanath'],
            ['short_code' => 'H', 'name' => 'Dr Hirushini', 'is_active' => false],
            ['short_code' => 'T', 'name' => 'Dr Thisara'],
            ['short_code' => 'K', 'name' => 'Dr Kasun'],
            ['short_code' => 'E', 'name' => 'Dr Enasha'],
            ['short_code' => 'G', 'name' => 'Dr Ganga'],
            ['short_code' => 'A', 'name' => 'Dr Amarasinghe'],
            ['short_code' => 'M', 'name' => 'Dr Mareena'],
            ['short_code' => 'R', 'name' => 'Dr Rajinda'],
            ['short_code' => 'B', 'name' => 'Dr Buddhima'],
            ['short_code' => 'L', 'name' => 'Dr Lasantha'],
            ['short_code' => 'U', 'name' => 'Dr Nadun'],
            ['short_code' => 'I', 'name' => 'Dr Amila'],
            ['short_code' => 'C', 'name' => 'Dr Umanga'],
        ];

        foreach ($doctors as $doctor) {
            Doctor::firstOrCreate(
                ['short_code' => $doctor['short_code']],
                ['name' => $doctor['name'], 'is_active' => $doctor['is_active'] ?? true],
            );
        }
    }
}
