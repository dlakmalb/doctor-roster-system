<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RosterCandidateRanker
{
    /** @var array<int, array<int, RosterShift>> */
    private array $preferences = [];

    /** @var array<int, array<int, true>> */
    private array $fulfilled = [];

    /** @var array<int, int> */
    private array $scheduledMinutes = [];

    /** @var array<int, int> */
    private array $nightCounts = [];

    /** @var array<int, int> */
    private array $optionalCounts = [];

    /** @var array<int, array<string, int>> */
    private array $weekendCounts = [];

    /** @var array<int, DoctorMonthlyWorkload> */
    private array $history = [];

    private CarbonImmutable $firstDate;

    public function __construct(private DoctorAssignmentEligibilityService $eligibility) {}

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, DoctorRequest>  $preferredRequests
     * @param  Collection<int, DoctorMonthlyWorkload>  $history
     */
    public function initialize(Collection $shifts, Collection $preferredRequests, Collection $history, CarbonImmutable $firstDate): void
    {
        $this->preferences = $this->fulfilled = $this->scheduledMinutes = $this->nightCounts = $this->optionalCounts = $this->weekendCounts = [];
        $this->history = $history->all();
        $this->firstDate = $firstDate;

        $shiftByDateAndType = [];
        foreach ($shifts as $shift) {
            $shiftByDateAndType[$shift->shift_date->toDateString()][$shift->shift_type_id] = $shift;
        }

        foreach ($preferredRequests as $request) {
            $shift = $shiftByDateAndType[$request->request_date->toDateString()][$request->shift_type_id] ?? null;
            if ($shift !== null) {
                $this->preferences[$request->doctor_id][$shift->id] = $shift;
            }
        }

        foreach ($shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $this->record($assignment->doctor_id, $shift, $assignment->role);
            }
        }
    }

    public function record(int $doctorId, RosterShift $shift, RosterAssignmentRole $role): void
    {
        if ($role === RosterAssignmentRole::Optional) {
            $this->optionalCounts[$doctorId] = ($this->optionalCounts[$doctorId] ?? 0) + 1;

            return;
        }

        $this->scheduledMinutes[$doctorId] = ($this->scheduledMinutes[$doctorId] ?? 0) + $shift->shiftType->duration_minutes;
        if ($shift->shiftType->is_overnight) {
            $this->nightCounts[$doctorId] = ($this->nightCounts[$doctorId] ?? 0) + 1;
        }
        $weekend = $this->weekendKey($shift);
        if ($weekend !== null) {
            $this->weekendCounts[$doctorId][$weekend] = ($this->weekendCounts[$doctorId][$weekend] ?? 0) + 1;
        }
        if (isset($this->preferences[$doctorId][$shift->id])) {
            $this->fulfilled[$doctorId][$shift->id] = true;
        }
    }

    public function weekendKey(RosterShift $shift): ?string
    {
        $date = CarbonImmutable::instance($shift->shift_date);
        if ($date->isFriday() && $shift->shiftType->code === 'weekday_night') {
            return $date->addDay()->toDateString();
        }
        if ($date->isSaturday() && str_starts_with($shift->shiftType->code, 'weekend_')) {
            return $date->toDateString();
        }
        if ($date->isSunday() && str_starts_with($shift->shiftType->code, 'weekend_')) {
            return $date->subDay()->toDateString();
        }

        return null;
    }

    /**
     * @param  Collection<int, RosterShift>  $assignedShifts
     * @return list<int>
     */
    public function dimensions(Doctor $doctor, RosterShift $shift, RosterAssignmentRole $role, Collection $assignedShifts): array
    {
        $doctorId = $doctor->id;
        $history = $this->history[$doctorId] ?? null;
        $dimensions = [];

        if ($role === RosterAssignmentRole::Main) {
            $dimensions[] = isset($this->preferences[$doctorId][$shift->id]) ? 0 : 1;
        }

        $destroyedPreferences = 0;
        foreach ($this->preferences[$doctorId] ?? [] as $preferredShift) {
            if (isset($this->fulfilled[$doctorId][$preferredShift->id])) {
                continue;
            }
            if ($preferredShift->shift_date->lessThan($shift->shift_date)
                || ($preferredShift->shift_date->isSameDay($shift->shift_date)
                    && $preferredShift->shiftType->start_time < $shift->shiftType->start_time)) {
                continue;
            }
            if ($preferredShift->id === $shift->id && $role === RosterAssignmentRole::Main) {
                continue;
            }
            if ($assignedShifts->contains(fn (RosterShift $assigned): bool => $this->eligibility->shiftConflict($preferredShift, $assigned) !== null)) {
                continue;
            }
            if ($this->eligibility->shiftConflict($preferredShift, $shift) !== null) {
                $destroyedPreferences++;
            }
        }
        $dimensions[] = $destroyedPreferences;

        $weekend = $this->weekendKey($shift);
        if ($role === RosterAssignmentRole::Main && $weekend !== null) {
            $previousWeekend = CarbonImmutable::parse($weekend)->subWeek()->toDateString();
            $historicalWeekend = $this->firstDate->subDay()->startOfWeek(CarbonInterface::SATURDAY)->toDateString();
            $workedPrevious = ($this->weekendCounts[$doctorId][$previousWeekend] ?? 0) > 0
                || ($previousWeekend === $historicalWeekend && ($history->worked_final_weekend ?? false));
            $dimensions[] = $workedPrevious ? 1 : 0;
            $dimensions[] = ($this->weekendCounts[$doctorId][$weekend] ?? 0)
                + ($weekend === $historicalWeekend && ($history->worked_final_weekend ?? false) ? 1 : 0);
        }

        if ($role === RosterAssignmentRole::Main && $shift->shiftType->is_overnight) {
            $dimensions[] = ($history->actual_night_duty_count ?? 0) + ($this->nightCounts[$doctorId] ?? 0);
        }
        if ($role === RosterAssignmentRole::Optional) {
            $dimensions[] = ($history->optional_assignment_count ?? 0) + ($this->optionalCounts[$doctorId] ?? 0);
        }
        $dimensions[] = ($history->closing_balance_minutes ?? 0) + ($this->scheduledMinutes[$doctorId] ?? 0);

        return $dimensions;
    }

    /**
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, Collection<int, RosterShift>>  $assignedByDoctor
     */
    public function select(Collection $doctors, RosterShift $shift, RosterAssignmentRole $role, Collection $assignedByDoctor): ?Doctor
    {
        $best = [];
        $bestDimensions = null;
        foreach ($doctors as $doctor) {
            $dimensions = $this->dimensions($doctor, $shift, $role, $assignedByDoctor->get($doctor->id, collect()));
            if ($bestDimensions === null || $dimensions < $bestDimensions) {
                $bestDimensions = $dimensions;
                $best = [$doctor];
            } elseif ($dimensions === $bestDimensions) {
                $best[] = $doctor;
            }
        }

        return $best === [] ? null : $best[random_int(0, count($best) - 1)];
    }
}
