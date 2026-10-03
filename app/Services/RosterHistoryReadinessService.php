<?php

namespace App\Services;

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use Carbon\CarbonImmutable;

class RosterHistoryReadinessService
{
    /** @return array{ready: bool, message: string|null, year: int, month: int, action: string} */
    public function forMonth(int $year, int $month): array
    {
        $previous = CarbonImmutable::create($year, $month, 1)->subMonth();
        $previousRoster = Roster::query()->where('year', $previous->year)->where('month', $previous->month)->first();
        $doctorIds = Doctor::query()->pluck('id');
        $doctorCount = $doctorIds->count();
        $rows = DoctorMonthlyWorkload::query()->where('year', $previous->year)->where('month', $previous->month);
        $complete = $doctorCount > 0 && (clone $rows)->whereIn('doctor_id', $doctorIds)->count() === $doctorCount;
        if ($previousRoster !== null) {
            $ready = $previousRoster->status === RosterStatus::Final && $previousRoster->actual_work_confirmed_at !== null && $complete
                && (clone $rows)->whereIn('doctor_id', $doctorIds)->where('roster_id', $previousRoster->id)->where('source', DoctorMonthlyWorkloadSource::System->value)->count() === $doctorCount;
            $action = 'review';
            $message = $ready ? null : "Confirm {$previous->format('F Y')} actual work before generating {$this->label($year, $month)}.";
        } else {
            $ready = $complete && (clone $rows)->whereIn('doctor_id', $doctorIds)->where('source', DoctorMonthlyWorkloadSource::ManualInitial->value)->count() === $doctorCount;
            $action = 'initial_setup';
            $message = $ready ? null : "Complete Initial Setup for {$previous->format('F Y')} before generating {$this->label($year, $month)}.";
        }

        return ['ready' => $ready, 'message' => $message, 'year' => $previous->year, 'month' => $previous->month, 'action' => $action];
    }

    private function label(int $year, int $month): string
    {
        return CarbonImmutable::create($year, $month, 1)->format('F Y');
    }
}
