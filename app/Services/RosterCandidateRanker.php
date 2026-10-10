<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RosterCandidateRanker
{
    /** @var array<int, array<int, RosterShift>> */
    private array $preferences = [];

    /** @var array<int, array<int, array<int, true>>> */
    private array $monthlyWeekdayPreferences = [];

    private bool $hasMonthlyWeekdayPreferences = false;

    /** @var array<int, int> */
    private array $shiftWeekdays = [];

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

    /** @var array<int, array<int, bool>> */
    private array $shiftConflicts = [];

    private CarbonImmutable $firstDate;

    /** @var array<int, Collection<int, DoctorWeekendGroupMembership>> */
    private array $weekendGroups = [];

    /** @var array<string, string> */
    private array $scheduledWeekendGroups = [];

    public function __construct(private DoctorAssignmentEligibilityService $eligibility) {}

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, DoctorRequest>  $preferredRequests
     * @param  Collection<int, DoctorMonthlyWorkload>  $history
     * @param  array<int, array<int, true>>  $restrictedShiftTypes
     * @param  Collection<int, DoctorMonthlyWeekdayPreference>  $monthlyWeekdayPreferences
     */
    public function initialize(Collection $shifts, Collection $preferredRequests, Collection $history, CarbonImmutable $firstDate, array $restrictedShiftTypes = [], ?Collection $monthlyWeekdayPreferences = null, array $weekendRotation = []): void
    {
        $this->preferences = [];
        $this->monthlyWeekdayPreferences = [];
        $this->hasMonthlyWeekdayPreferences = false;
        $this->shiftWeekdays = [];
        $this->shiftConflicts = [];
        $this->resetRecordedAssignments();
        $this->history = $history->all();
        $this->firstDate = $firstDate;
        $this->weekendGroups = $weekendRotation['assignments'] ?? [];
        $this->scheduledWeekendGroups = $weekendRotation['expected'] ?? [];

        $shiftByDateAndType = [];
        foreach ($shifts as $shift) {
            $shiftByDateAndType[$shift->shift_date->toDateString()][$shift->shift_type_id] = $shift;
        }

        foreach ($preferredRequests as $request) {
            if (isset($restrictedShiftTypes[$request->doctor_id][$request->shift_type_id])) {
                continue;
            }
            $shift = $shiftByDateAndType[$request->request_date->toDateString()][$request->shift_type_id] ?? null;
            if ($shift !== null) {
                $this->preferences[$request->doctor_id][$shift->id] = $shift;
            }
        }

        foreach ($monthlyWeekdayPreferences ?? collect() as $preference) {
            if (isset($restrictedShiftTypes[$preference->doctor_id][$preference->shift_type_id])) {
                continue;
            }

            $this->monthlyWeekdayPreferences[$preference->doctor_id][$preference->shift_type_id][$preference->weekday] = true;
            $this->hasMonthlyWeekdayPreferences = true;
        }

        if ($this->hasMonthlyWeekdayPreferences) {
            foreach ($shifts as $shift) {
                $this->shiftWeekdays[$shift->id] = $shift->shift_date->dayOfWeekIso;
            }
        }

        foreach ($shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $this->record($assignment->doctor_id, $shift, $assignment->role);
            }
        }
    }

    public function resetRecordedAssignments(): void
    {
        $this->fulfilled = $this->scheduledMinutes = $this->nightCounts = $this->optionalCounts = $this->weekendCounts = [];
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

    public function unrecord(int $doctorId, RosterShift $shift, RosterAssignmentRole $role): void
    {
        if ($role === RosterAssignmentRole::Optional) {
            $this->decrement($this->optionalCounts, $doctorId, 1);

            return;
        }

        $this->decrement($this->scheduledMinutes, $doctorId, $shift->shiftType->duration_minutes);
        if ($shift->shiftType->is_overnight) {
            $this->decrement($this->nightCounts, $doctorId, 1);
        }
        $weekend = $this->weekendKey($shift);
        if ($weekend !== null) {
            $weekendCounts = $this->weekendCounts[$doctorId] ?? [];
            $this->decrement($weekendCounts, $weekend, 1);
            if ($weekendCounts === []) {
                unset($this->weekendCounts[$doctorId]);
            } else {
                $this->weekendCounts[$doctorId] = $weekendCounts;
            }
        }
        if (isset($this->preferences[$doctorId][$shift->id])) {
            unset($this->fulfilled[$doctorId][$shift->id]);
            if (($this->fulfilled[$doctorId] ?? []) === []) {
                unset($this->fulfilled[$doctorId]);
            }
        }
    }

    /**
     * @return array{
     *     fulfilled: array<int, array<int, true>>,
     *     scheduledMinutes: array<int, int>,
     *     nightCounts: array<int, int>,
     *     optionalCounts: array<int, int>,
     *     weekendCounts: array<int, array<string, int>>
     * }
     */
    public function recordedAssignmentsSnapshot(): array
    {
        return [
            'fulfilled' => $this->fulfilled,
            'scheduledMinutes' => $this->scheduledMinutes,
            'nightCounts' => $this->nightCounts,
            'optionalCounts' => $this->optionalCounts,
            'weekendCounts' => $this->weekendCounts,
        ];
    }

    /**
     * @param array{
     *     fulfilled: array<int, array<int, true>>,
     *     scheduledMinutes: array<int, int>,
     *     nightCounts: array<int, int>,
     *     optionalCounts: array<int, int>,
     *     weekendCounts: array<int, array<string, int>>
     * } $snapshot
     */
    public function restoreRecordedAssignments(array $snapshot): void
    {
        $this->fulfilled = $snapshot['fulfilled'];
        $this->scheduledMinutes = $snapshot['scheduledMinutes'];
        $this->nightCounts = $snapshot['nightCounts'];
        $this->optionalCounts = $snapshot['optionalCounts'];
        $this->weekendCounts = $snapshot['weekendCounts'];
    }

    /** @param array<int|string, int> $counts */
    private function decrement(array &$counts, int|string $key, int $amount): void
    {
        $counts[$key] -= $amount;
        if ($counts[$key] === 0) {
            unset($counts[$key]);
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

    public function mainStage(RosterShift $shift): int
    {
        if ($this->weekendKey($shift) !== null) {
            return 0;
        }

        return match ($shift->shiftType->code) {
            'weekday_night' => 1,
            'weekday_day' => 2,
            'weekday_evening' => 3,
            default => 4,
        };
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

        $rotationWeekend = $this->rotationWeekendStart($shift);
        if ($role === RosterAssignmentRole::Main && $rotationWeekend !== null && isset($this->scheduledWeekendGroups[$rotationWeekend])) {
            $actualGroup = $this->groupFor($doctorId, $rotationWeekend);
            $dimensions[] = $actualGroup === $this->scheduledWeekendGroups[$rotationWeekend] ? 0 : 1;
        }

        if ($role === RosterAssignmentRole::Main) {
            $dimensions[] = isset($this->preferences[$doctorId][$shift->id]) ? 0 : 1;
        }

        $dimensions[] = count($this->blockedPreferredShifts($doctor, $shift, $role, $assignedShifts, true));

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
        if ($this->hasMonthlyWeekdayPreferences) {
            $weekday = $this->shiftWeekdays[$shift->id] ?? $shift->shift_date->dayOfWeekIso;
            $dimensions[] = isset($this->monthlyWeekdayPreferences[$doctorId][$shift->shift_type_id][$weekday]) ? 0 : 1;
        }

        return $dimensions;
    }

    public function rotationWeekendStart(RosterShift $shift): ?string
    {
        if (! str_starts_with($shift->shiftType->code, 'weekend_')) {
            return null;
        }
        $date = CarbonImmutable::instance($shift->shift_date);

        return ($date->isSunday() ? $date->subDay() : $date)->toDateString();
    }

    public function groupFor(int $doctorId, string $saturday): ?string
    {
        return ($this->weekendGroups[$doctorId] ?? collect())
            ->filter(fn (DoctorWeekendGroupMembership $membership): bool => $membership->effective_from_saturday->toDateString() <= $saturday)
            ->last()?->group_code;
    }

    public function isScheduledGroupCandidate(Doctor $doctor, RosterShift $shift, RosterAssignmentRole $role): ?bool
    {
        if ($role !== RosterAssignmentRole::Main) {
            return null;
        }
        $saturday = $this->rotationWeekendStart($shift);
        if ($saturday === null || ! isset($this->scheduledWeekendGroups[$saturday])) {
            return null;
        }

        return $this->groupFor($doctor->id, $saturday) === $this->scheduledWeekendGroups[$saturday];
    }

    /**
     * @param  Collection<int, RosterShift>  $assignedShifts
     * @return list<RosterShift>
     */
    public function blockedPreferredShifts(Doctor $doctor, RosterShift $shift, RosterAssignmentRole $role, Collection $assignedShifts, bool $usePlanningOrder = false): array
    {
        $blocked = [];
        $doctorId = $doctor->id;
        foreach ($this->preferences[$doctorId] ?? [] as $preferredShift) {
            if (isset($this->fulfilled[$doctorId][$preferredShift->id])) {
                continue;
            }
            if ($this->wasPlannedBefore($preferredShift, $shift, $role, $usePlanningOrder)) {
                continue;
            }
            if ($preferredShift->id === $shift->id && $role === RosterAssignmentRole::Main) {
                continue;
            }
            if ($assignedShifts->contains(fn (RosterShift $assigned): bool => $this->shiftsConflict($preferredShift, $assigned))) {
                continue;
            }
            if ($this->shiftsConflict($preferredShift, $shift)) {
                $blocked[] = $preferredShift;
            }
        }

        return $blocked;
    }

    private function shiftsConflict(RosterShift $shift, RosterShift $otherShift): bool
    {
        if (! isset($this->shiftConflicts[$shift->id][$otherShift->id])) {
            $this->shiftConflicts[$shift->id][$otherShift->id] = $this->eligibility->shiftConflict($shift, $otherShift) !== null;
        }

        return $this->shiftConflicts[$shift->id][$otherShift->id];
    }

    private function wasPlannedBefore(RosterShift $preferredShift, RosterShift $candidateShift, RosterAssignmentRole $role, bool $usePlanningOrder): bool
    {
        if ($usePlanningOrder && $role === RosterAssignmentRole::Main) {
            $stageComparison = $this->mainStage($preferredShift) <=> $this->mainStage($candidateShift);
            if ($stageComparison !== 0) {
                return $stageComparison < 0;
            }
        }

        return $preferredShift->shift_date->lessThan($candidateShift->shift_date)
            || ($preferredShift->shift_date->isSameDay($candidateShift->shift_date)
                && $preferredShift->shiftType->start_time < $candidateShift->shiftType->start_time);
    }

    /**
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, Collection<int, RosterShift>>  $assignedByDoctor
     */
    public function select(Collection $doctors, RosterShift $shift, RosterAssignmentRole $role, Collection $assignedByDoctor): ?Doctor
    {
        return $this->ordered($doctors, $shift, $role, $assignedByDoctor)->first();
    }

    /**
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, Collection<int, RosterShift>>  $assignedByDoctor
     * @return Collection<int, Doctor>
     */
    public function ordered(Collection $doctors, RosterShift $shift, RosterAssignmentRole $role, Collection $assignedByDoctor): Collection
    {
        $ranked = $doctors->map(fn (Doctor $doctor): array => [
            'doctor' => $doctor,
            'dimensions' => $this->dimensions($doctor, $shift, $role, $assignedByDoctor->get($doctor->id, collect())),
        ])->values()->all();

        shuffle($ranked);
        usort($ranked, fn (array $left, array $right): int => $left['dimensions'] <=> $right['dimensions']);

        return collect(array_map(fn (array $candidate): Doctor => $candidate['doctor'], $ranked));
    }
}
