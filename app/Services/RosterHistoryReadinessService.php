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
    public function __construct(private DoctorMonthlyParticipationService $participation) {}

    /** @return array{ready: bool, message: string|null, year: int, month: int, action: string, basis: string|null} */
    public function forMonth(int $year, int $month): array
    {
        $previous = CarbonImmutable::create($year, $month, 1)->subMonth();
        $previousRoster = Roster::query()->where('year', $previous->year)->where('month', $previous->month)->first();
        $doctorIds = Doctor::query()->pluck('id');
        $historicalDoctorIds = $previousRoster === null || $previousRoster->status !== RosterStatus::Final
            ? $doctorIds
            : $this->participation->doctorIdsForRoster($previousRoster);
        $doctorCount = $historicalDoctorIds->count();
        $rows = DoctorMonthlyWorkload::query()->where('year', $previous->year)->where('month', $previous->month);
        $complete = $doctorCount > 0 && (clone $rows)->whereIn('doctor_id', $historicalDoctorIds)->count() === $doctorCount;
        if ($previousRoster !== null) {
            $participationComplete = $this->participation->hasCompleteMonth($previous->year, $previous->month, $historicalDoctorIds);
            $actualComplete = $complete && (clone $rows)->whereIn('doctor_id', $historicalDoctorIds)->where('roster_id', $previousRoster->id)->where('source', DoctorMonthlyWorkloadSource::System->value)->count() === $doctorCount;
            $ready = $participationComplete && $previousRoster->status === RosterStatus::Final && ($previousRoster->actual_work_confirmed_at === null || $actualComplete);
            $basis = ! $ready ? null : ($previousRoster->actual_work_confirmed_at === null ? 'final_planned' : 'confirmed_actual');
            $action = $previousRoster->status === RosterStatus::Draft ? 'finalize' : 'review';
            $message = match (true) {
                $previousRoster->status === RosterStatus::Draft => "Finalize {$previous->format('F Y')} before generating {$this->label($year, $month)}.",
                ! $participationComplete => "Complete {$previous->format('F Y')} participation history before generating {$this->label($year, $month)}.",
                $previousRoster->actual_work_confirmed_at !== null && ! $actualComplete => "Complete {$previous->format('F Y')} confirmed actual history before generating {$this->label($year, $month)}.",
                $basis === 'final_planned' => "Using finalized {$previous->format('F Y')} roster history until actual work is confirmed.",
                default => null,
            };
        } else {
            $participationComplete = $this->participation->hasCompleteMonth($previous->year, $previous->month, $historicalDoctorIds);
            $ready = $participationComplete && $complete && (clone $rows)->whereIn('doctor_id', $historicalDoctorIds)->where('source', DoctorMonthlyWorkloadSource::ManualInitial->value)->count() === $doctorCount;
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
