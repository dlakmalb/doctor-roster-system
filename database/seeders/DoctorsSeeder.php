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
            ['short_code' => 'H', 'name' => 'Dr Hirushini'],
            ['short_code' => 'T', 'name' => 'Dr Thisara'],
            ['short_code' => 'K', 'name' => 'Dr Kasun'],
            ['short_code' => 'E', 'name' => 'Dr Enasha'],
            ['short_code' => 'G', 'name' => 'Dr Ganga'],
            ['short_code' => 'A', 'name' => 'Dr Amarasinghe'],
            ['short_code' => 'D', 'name' => 'Dr Dulan'],
            ['short_code' => 'R', 'name' => 'Dr Rajinda'],
            ['short_code' => 'B', 'name' => 'Dr Buddhima'],
            ['short_code' => 'L', 'name' => 'Dr Lasantha'],
            ['short_code' => 'U', 'name' => 'Dr Nadun'],
            ['short_code' => 'I', 'name' => 'Dr Amila'],
        ];

        foreach ($doctors as $doctor) {
            Doctor::firstOrCreate(
                ['short_code' => $doctor['short_code']],
                ['name' => $doctor['name'], 'is_active' => true],
            );
        }
    }
}
