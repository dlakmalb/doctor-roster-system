<?php

namespace App\Services;

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RosterPlanningHistoryService
{
    public function __construct(private DoctorMonthlyWorkloadService $workloads, private RosterCandidateRanker $ranker) {}

    /** @return Collection<int, DoctorMonthlyWorkload> */
    public function forMonth(int $year, int $month, bool $requireFinal = true): Collection
    {
        $source = CarbonImmutable::create($year, $month, 1)->subMonth();
        $roster = Roster::query()->where('year', $source->year)->where('month', $source->month)->first();
        $rows = DoctorMonthlyWorkload::query()->where('year', $source->year)->where('month', $source->month)->get();

        if ($roster === null) {
            return $rows->where('source', DoctorMonthlyWorkloadSource::ManualInitial)->keyBy('doctor_id');
        }
        if ($roster->status !== RosterStatus::Final) {
            if (! $requireFinal) {
                return collect();
            }
            throw ValidationException::withMessages(['roster' => "Finalize {$source->format('F Y')} before generating ".CarbonImmutable::create($year, $month, 1)->format('F Y').'.']);
        }
        if ($roster->actual_work_confirmed_at !== null) {
            return $rows->where('source', DoctorMonthlyWorkloadSource::System)->where('roster_id', $roster->id)->keyBy('doctor_id');
        }

        return $this->fromPlannedRoster($roster);
    }

    public function freshness(int $year, int $month): ?CarbonInterface
    {
        $source = CarbonImmutable::create($year, $month, 1)->subMonth();
        $roster = Roster::query()->where('year', $source->year)->where('month', $source->month)->first();
        $rows = DoctorMonthlyWorkload::query()->where('year', $source->year)->where('month', $source->month);

        if ($roster === null) {
            $updated = (clone $rows)->where('source', DoctorMonthlyWorkloadSource::ManualInitial)->max('updated_at');

            return $updated === null ? null : CarbonImmutable::parse($updated);
        }
        if ($roster->status !== RosterStatus::Final) {
            return $roster->reopened_at;
        }
        if ($roster->actual_work_confirmed_at === null) {
            $planningAuthority = $roster->finalized_at;
            if ($roster->updated_at !== null && ($planningAuthority === null || $roster->updated_at->greaterThan($planningAuthority))) {
                $planningAuthority = $roster->updated_at;
            }
            $openingHistory = $this->freshness($roster->year, $roster->month);

            return $openingHistory !== null && ($planningAuthority === null || $openingHistory->greaterThan($planningAuthority))
                ? $openingHistory : $planningAuthority;
        }
        $updated = (clone $rows)->where('source', DoctorMonthlyWorkloadSource::System)->where('roster_id', $roster->id)->max('updated_at');
        $workloadUpdated = $updated === null ? null : CarbonImmutable::parse($updated);

        return $workloadUpdated !== null && $workloadUpdated->greaterThan($roster->actual_work_confirmed_at)
            ? $workloadUpdated : $roster->actual_work_confirmed_at;
    }

    /** @return Collection<int, DoctorMonthlyWorkload> */
    private function fromPlannedRoster(Roster $roster): Collection
    {
        $doctors = Doctor::query()->get();
        $openings = $this->forMonth($roster->year, $roster->month, false);
        $excluded = DoctorMonthlyExclusion::query()->where('year', $roster->year)->where('month', $roster->month)->pluck('doctor_id')->flip();
        $facts = [];
        foreach ($doctors as $doctor) {
            $facts[$doctor->id] = [
                'doctor_id' => $doctor->id,
                'actual_worked_minutes' => 0,
                'actual_night_duty_count' => 0,
                'optional_assignment_count' => 0,
                'worked_final_weekend' => false,
                'most_recent_night_shift_at' => null,
                'is_month_excluded' => $excluded->has($doctor->id),
                'opening_balance_minutes' => $openings->get($doctor->id)->closing_balance_minutes ?? 0,
            ];
        }
        $lastDate = CarbonImmutable::create($roster->year, $roster->month, 1)->endOfMonth();
        $finalWeekend = ($lastDate->isFriday() ? $lastDate->addDay() : $lastDate->startOfWeek(CarbonInterface::SATURDAY))->toDateString();
        foreach ($roster->shifts()->with(['shiftType', 'assignments'])->get() as $shift) {
            foreach ($shift->assignments as $assignment) {
                if (! isset($facts[$assignment->doctor_id])) {
                    throw ValidationException::withMessages(['roster' => 'A planned doctor record is missing.']);
                }
                if ($assignment->role === RosterAssignmentRole::Optional) {
                    $facts[$assignment->doctor_id]['optional_assignment_count']++;

                    continue;
                }
                $facts[$assignment->doctor_id]['actual_worked_minutes'] += $shift->shiftType->duration_minutes;
                if ($shift->shiftType->is_overnight) {
                    $facts[$assignment->doctor_id]['actual_night_duty_count']++;
                    $startedAt = $shift->shift_date->format('Y-m-d').' '.$shift->shiftType->start_time;
                    if ($facts[$assignment->doctor_id]['most_recent_night_shift_at'] === null || $startedAt > $facts[$assignment->doctor_id]['most_recent_night_shift_at']) {
                        $facts[$assignment->doctor_id]['most_recent_night_shift_at'] = $startedAt;
                    }
                }
                if ($this->ranker->weekendKey($shift) === $finalWeekend) {
                    $facts[$assignment->doctor_id]['worked_final_weekend'] = true;
                }
            }
        }

        return collect($this->workloads->balances(array_values($facts))['rows'])
            ->mapWithKeys(function (array $row) use ($roster): array {
                $doctorId = $row['doctor_id'];
                unset($row['doctor_id']);

                return [$doctorId => new DoctorMonthlyWorkload([...$row, 'doctor_id' => $doctorId, 'year' => $roster->year, 'month' => $roster->month])];
            });
    }
}
