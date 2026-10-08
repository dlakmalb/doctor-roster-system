<?php

namespace App\Services;

use App\Enums\ActualWorkExceptionType;
use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type WorkloadRow array{doctor_id: int, is_participating: bool, actual_worked_minutes: int, is_month_excluded: bool, opening_balance_minutes: int, actual_night_duty_count?: int, optional_assignment_count?: int, worked_final_weekend?: bool, most_recent_night_shift_at?: string|null, name?: string, short_code?: string, monthly_adjustment_minutes?: int, closing_balance_minutes?: int}
 * @phpstan-type BalancedRow array{doctor_id: int, is_participating: bool, actual_worked_minutes: int, is_month_excluded: bool, opening_balance_minutes: int, monthly_adjustment_minutes: int, closing_balance_minutes: int, actual_night_duty_count?: int, optional_assignment_count?: int, worked_final_weekend?: bool, most_recent_night_shift_at?: string|null, name?: string, short_code?: string}
 */
class DoctorMonthlyWorkloadService
{
    public function __construct(private RosterCandidateRanker $ranker, private DoctorMonthlyParticipationService $participation) {}

    /**
     * @return array{average: int, rows: list<BalancedRow>}
     */
    public function preview(Roster $roster): array
    {
        $doctors = Doctor::query()->orderBy('name')->get();
        $shifts = $roster->shifts()->with(['shiftType', 'assignments', 'assignments.actualWorkExceptions'])->get();
        $previous = CarbonImmutable::create($roster->year, $roster->month, 1)->subMonth();
        $openings = DoctorMonthlyWorkload::query()->where('year', $previous->year)->where('month', $previous->month)->get()->keyBy('doctor_id');
        $participation = $this->participation->forMonth($roster->year, $roster->month, $doctors->modelKeys());
        $excluded = DoctorMonthlyExclusion::query()->where('year', $roster->year)->where('month', $roster->month)->pluck('doctor_id')->flip();
        $facts = [];
        foreach ($doctors as $doctor) {
            $facts[$doctor->id] = [
                'doctor_id' => $doctor->id,
                'is_participating' => $participation->get($doctor->id),
                'name' => $doctor->name,
                'short_code' => $doctor->short_code,
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
        foreach ($shifts as $shift) {
            $actualOnShift = [];
            foreach ($shift->assignments as $assignment) {
                if (! isset($facts[$assignment->doctor_id])) {
                    throw ValidationException::withMessages(['actual_work' => 'A planned doctor record is missing.']);
                }
                $exceptions = $assignment->actualWorkExceptions;
                if ($exceptions->count() > 1) {
                    throw ValidationException::withMessages(['actual_work' => 'An assignment has multiple actual-work exceptions.']);
                }
                $exception = $exceptions->first();
                if ($exception !== null && $exception->roster_shift_id !== $shift->id) {
                    throw ValidationException::withMessages(['actual_work' => 'An exception belongs to another shift.']);
                }
                if ($assignment->role === RosterAssignmentRole::Optional) {
                    $facts[$assignment->doctor_id]['optional_assignment_count']++;
                    if ($exception !== null && ($exception->exception_type !== ActualWorkExceptionType::OptionalWorked || $exception->actual_doctor_id !== $assignment->doctor_id)) {
                        throw ValidationException::withMessages(['actual_work' => 'Invalid Optional actual-work exception.']);
                    }
                    $actualDoctorId = $exception === null ? null : $assignment->doctor_id;
                } else {
                    if ($exception !== null && ! in_array($exception->exception_type, [ActualWorkExceptionType::MainAbsent, ActualWorkExceptionType::Replacement], true)) {
                        throw ValidationException::withMessages(['actual_work' => 'Invalid Main actual-work exception.']);
                    }
                    if ($exception?->exception_type === ActualWorkExceptionType::MainAbsent && $exception->actual_doctor_id !== null) {
                        throw ValidationException::withMessages(['actual_work' => 'Invalid Main absence record.']);
                    }
                    if ($exception?->exception_type === ActualWorkExceptionType::Replacement) {
                        if ($exception->actual_doctor_id === null || $exception->actual_doctor_id === $assignment->doctor_id) {
                            throw ValidationException::withMessages(['actual_work' => 'A replacement must be another doctor.']);
                        }
                    }
                    $actualDoctorId = match ($exception?->exception_type) {
                        ActualWorkExceptionType::MainAbsent => null,
                        ActualWorkExceptionType::Replacement => $exception->actual_doctor_id,
                        default => $assignment->doctor_id,
                    };
                }
                if ($actualDoctorId === null) {
                    continue;
                }
                if (! isset($facts[$actualDoctorId]) || isset($actualOnShift[$actualDoctorId])) {
                    throw ValidationException::withMessages(['actual_work' => 'A doctor can work only one actual duty per shift.']);
                }
                $actualOnShift[$actualDoctorId] = true;
                $facts[$actualDoctorId]['actual_worked_minutes'] += $shift->shiftType->duration_minutes;
                if ($shift->shiftType->is_overnight) {
                    $facts[$actualDoctorId]['actual_night_duty_count']++;
                    $startedAt = $shift->shift_date->format('Y-m-d').' '.$shift->shiftType->start_time;
                    if ($facts[$actualDoctorId]['most_recent_night_shift_at'] === null || $startedAt > $facts[$actualDoctorId]['most_recent_night_shift_at']) {
                        $facts[$actualDoctorId]['most_recent_night_shift_at'] = $startedAt;
                    }
                }
                if ($this->ranker->weekendKey($shift) === $finalWeekend) {
                    $facts[$actualDoctorId]['worked_final_weekend'] = true;
                }
            }
        }

        return $this->balances(array_values($facts));
    }

    /**
     * @param  list<WorkloadRow>  $facts
     * @return array{average: int, rows: list<BalancedRow>}
     */
    public function balances(array $facts): array
    {
        $included = array_values(array_filter($facts, fn (array $row): bool => $row['is_participating'] && ! $row['is_month_excluded']));
        $total = array_sum(array_column($included, 'actual_worked_minutes'));
        $average = count($included) === 0 ? 0 : intdiv(2 * $total + count($included), 2 * count($included));
        $balanced = [];
        foreach ($facts as $row) {
            $adjustment = ! $row['is_participating'] || $row['is_month_excluded']
                ? 0
                : $row['actual_worked_minutes'] - $average;
            $balanced[] = [...$row, 'monthly_adjustment_minutes' => $adjustment, 'closing_balance_minutes' => $row['opening_balance_minutes'] + $adjustment];
        }

        return ['average' => $average, 'rows' => $balanced];
    }

    /** @param array{average: int, rows: list<BalancedRow>}|null $preview */
    public function persistRoster(Roster $roster, ?array $preview = null): void
    {
        foreach (($preview ?? $this->preview($roster))['rows'] as $row) {
            $doctorId = $row['doctor_id'];
            unset($row['doctor_id']);
            if (array_key_exists('name', $row)) {
                unset($row['name']);
            }
            if (array_key_exists('short_code', $row)) {
                unset($row['short_code']);
            }
            unset($row['is_participating']);
            DoctorMonthlyWorkload::query()->updateOrCreate(
                ['doctor_id' => $doctorId, 'year' => $roster->year, 'month' => $roster->month],
                [...$row, 'roster_id' => $roster->id, 'source' => DoctorMonthlyWorkloadSource::System],
            );
        }
    }

    public function recalculateLater(int $year, int $month): void
    {
        $start = $year * 12 + $month;
        $periods = DoctorMonthlyWorkload::query()->whereRaw('(year * 12 + month) > ?', [$start])
            ->orderBy('year')->orderBy('month')->get()->groupBy(fn (DoctorMonthlyWorkload $row): string => $row->year.'-'.$row->month);
        $doctorIds = Doctor::query()->pluck('id');
        foreach ($periods as $rows) {
            $first = $rows->firstOrFail();
            if ($rows->count() !== $doctorIds->count() || $doctorIds->diff($rows->pluck('doctor_id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['history' => 'A later workload period is missing a doctor record.']);
            }
            $previous = CarbonImmutable::create($first->year, $first->month, 1)->subMonth();
            $openings = DoctorMonthlyWorkload::query()->where('year', $previous->year)->where('month', $previous->month)->get()->keyBy('doctor_id');
            $participation = $this->participation->forMonth($first->year, $first->month, $doctorIds);
            $facts = $rows->map(fn (DoctorMonthlyWorkload $row): array => [
                'doctor_id' => $row->doctor_id,
                'is_participating' => $participation->get($row->doctor_id),
                'actual_worked_minutes' => $row->actual_worked_minutes,
                'is_month_excluded' => $row->is_month_excluded,
                'opening_balance_minutes' => $openings->get($row->doctor_id)->closing_balance_minutes ?? 0,
            ])->all();
            $balanced = collect($this->balances(array_values($facts))['rows'])->keyBy('doctor_id');
            foreach ($rows as $row) {
                $next = $balanced->get($row->doctor_id);
                if ($next === null) {
                    throw ValidationException::withMessages(['history' => 'A later workload row could not be recalculated.']);
                }
                if ($row->opening_balance_minutes !== $next['opening_balance_minutes'] || $row->monthly_adjustment_minutes !== $next['monthly_adjustment_minutes'] || $row->closing_balance_minutes !== $next['closing_balance_minutes']) {
                    $row->update([
                        'opening_balance_minutes' => $next['opening_balance_minutes'],
                        'monthly_adjustment_minutes' => $next['monthly_adjustment_minutes'],
                        'closing_balance_minutes' => $next['closing_balance_minutes'],
                    ]);
                }
            }
        }
    }
}
