<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\Roster;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MonthlyWeekendEligibilityService
{
    public function __construct(private DoctorMonthlyParticipationService $participation) {}

    /** @return Collection<int, Doctor> */
    public function resolve(int $year, int $month, ?Roster $roster): Collection
    {
        if ($roster !== null) {
            $ids = $this->participation->doctorIdsForRoster($roster);
            $participating = DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)
                ->where('is_participating', true)->pluck('doctor_id');
            $doctors = Doctor::query()->whereIn('id', $ids->intersect($participating))->orderBy('id')->get();
        } else {
            if (DoctorMonthlyParticipation::query()->where('year', $year)->where('month', $month)->exists()) {
                throw ValidationException::withMessages([
                    'participation' => 'Participation records already exist for this month without a roster. Ask an administrator to resolve the participation history before confirming pairs or creating a roster; do not delete historical records.',
                ]);
            }
            $doctors = Doctor::query()->where('is_active', true)->orderBy('id')->get();
        }

        $excluded = DoctorMonthlyExclusion::query()->where('year', $year)->where('month', $month)->pluck('doctor_id')->all();

        return $doctors->reject(fn (Doctor $doctor): bool => in_array($doctor->id, $excluded))->values();
    }
}
