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
    /** @return array{ready: bool, message: string|null, year: int, month: int, action: string, basis: string|null} */
    public function forMonth(int $year, int $month): array
    {
        $previous = CarbonImmutable::create($year, $month, 1)->subMonth();
        $previousRoster = Roster::query()->where('year', $previous->year)->where('month', $previous->month)->first();
        $doctorIds = Doctor::query()->pluck('id');
        $doctorCount = $doctorIds->count();
        $rows = DoctorMonthlyWorkload::query()->where('year', $previous->year)->where('month', $previous->month);
        $complete = $doctorCount > 0 && (clone $rows)->whereIn('doctor_id', $doctorIds)->count() === $doctorCount;
        if ($previousRoster !== null) {
            $actualComplete = $complete && (clone $rows)->whereIn('doctor_id', $doctorIds)->where('roster_id', $previousRoster->id)->where('source', DoctorMonthlyWorkloadSource::System->value)->count() === $doctorCount;
            $ready = $previousRoster->status === RosterStatus::Final && ($previousRoster->actual_work_confirmed_at === null || $actualComplete);
            $basis = ! $ready ? null : ($previousRoster->actual_work_confirmed_at === null ? 'final_planned' : 'confirmed_actual');
            $action = $previousRoster->status === RosterStatus::Draft ? 'finalize' : 'review';
            $message = match (true) {
                $previousRoster->status === RosterStatus::Draft => "Finalize {$previous->format('F Y')} before generating {$this->label($year, $month)}.",
                $previousRoster->actual_work_confirmed_at !== null && ! $actualComplete => "Complete {$previous->format('F Y')} confirmed actual history before generating {$this->label($year, $month)}.",
                $basis === 'final_planned' => "Using finalized {$previous->format('F Y')} roster history until actual work is confirmed.",
                default => null,
            };
        } else {
            $ready = $complete && (clone $rows)->whereIn('doctor_id', $doctorIds)->where('source', DoctorMonthlyWorkloadSource::ManualInitial->value)->count() === $doctorCount;
            $action = 'initial_setup';
            $message = $ready ? null : "Complete Initial Setup for {$previous->format('F Y')} before generating {$this->label($year, $month)}.";
            $basis = $ready ? 'manual_initial' : null;
        }

        return ['ready' => $ready, 'message' => $message, 'year' => $previous->year, 'month' => $previous->month, 'action' => $action, 'basis' => $basis];
    }

    private function label(int $year, int $month): string
    {
        return CarbonImmutable::create($year, $month, 1)->format('F Y');
    }
}
