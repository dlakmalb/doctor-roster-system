<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\WeekendRotationConfiguration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class October2026WeekendRotationSeeder extends Seeder
{
    /** @var array<string, string> */
    private const SEPTEMBER_GROUPS = [
        'H' => 'A', 'T' => 'A', 'I' => 'A', 'A' => 'A', 'L' => 'A', 'R' => 'A', 'B' => 'A',
        'N' => 'B', 'S' => 'B', 'K' => 'B', 'E' => 'B', 'G' => 'B', 'M' => 'B', 'U' => 'B',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('October weekend rotation data may only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            $configuration = WeekendRotationConfiguration::query()->first();
            if ($configuration !== null && ($configuration->anchor_saturday->toDateString() !== '2026-09-26' || $configuration->anchor_group !== 'B')) {
                throw ValidationException::withMessages(['weekend_rotation' => 'An incompatible weekend rotation anchor already exists.']);
            }
            $configuration ??= WeekendRotationConfiguration::query()->create([
                'singleton_key' => 'primary',
                'anchor_saturday' => '2026-09-26',
                'anchor_group' => 'B',
            ]);

            foreach (self::SEPTEMBER_GROUPS as $shortCode => $group) {
                $this->persistMembership($shortCode, $group, '2026-09-26');
            }
            $this->persistMembership('H', null, '2026-10-03');
            $this->persistMembership('C', 'A', '2026-10-03');
        });
    }

    private function persistMembership(string $shortCode, ?string $group, string $effectiveSaturday): void
    {
        $doctor = Doctor::query()->where('short_code', $shortCode)->first();
        if ($doctor === null) {
            throw ValidationException::withMessages(['weekend_rotation' => "Doctor code $shortCode was not found."]);
        }

        $membership = DoctorWeekendGroupMembership::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('effective_from_saturday', $effectiveSaturday)
            ->first();
        if ($membership !== null && $membership->group_code !== $group) {
            throw ValidationException::withMessages(['weekend_rotation' => "Doctor $shortCode already has a conflicting membership on $effectiveSaturday."]);
        }
        if ($membership === null) {
            DoctorWeekendGroupMembership::query()->create([
                'doctor_id' => $doctor->id,
                'group_code' => $group,
                'effective_from_saturday' => $effectiveSaturday,
            ]);
        }
    }
}
