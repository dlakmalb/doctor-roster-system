<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RosterDraftContext
{
    /** @var Collection<int, RosterShift> */
    public Collection $shifts;

    /** @var Collection<int, Doctor> */
    public Collection $doctors;

    /** @var Collection<int, DoctorMonthlyWorkload> */
    public Collection $history;

    /** @var Collection<int, DoctorRequest> */
    public Collection $preferred;

    /** @var Collection<int|string, Collection<int, DoctorRequest>> */
    private Collection $dayOff;

    /** @var Collection<int, int> */
    private Collection $excluded;

    /** @var array<int, array<int, true>> */
    private array $restrictedShiftTypes = [];

    /** @var Collection<int, bool> */
    private Collection $participation;

    public bool $participationPopulationMatches = true;

    public bool $participationSnapshotIntegrityMatches = true;

    /** @var Collection<int, true>|null */
    public ?Collection $previousNightDoctorIds = null;

    public ?string $previousNightBoundaryError = null;

    /** @var Collection<int, true> */
    private Collection $finalDependentDoctorIds;

    private bool $checkActiveStatus = true;

    public function __construct(private DoctorAssignmentEligibilityService $eligibility, private RequestIntervalService $requestIntervals, private RosterCandidateRanker $ranker, private RosterPlanningHistoryService $planningHistory, private DoctorMonthlyParticipationService $monthlyParticipation, private PreviousNightBoundaryService $nightBoundary) {}

    public function load(Roster $roster): void
    {
        $this->participationPopulationMatches = true;
        $this->participationSnapshotIntegrityMatches = true;
        $this->checkActiveStatus = $roster->status === RosterStatus::Draft;
        $this->previousNightDoctorIds = null;
        $this->previousNightBoundaryError = null;
        $this->finalDependentDoctorIds = $this->nightBoundary->doctorIdsInFinalDependentRoster($roster->year, $roster->month);
        try {
            $this->previousNightDoctorIds = $this->nightBoundary->doctorIdsForMonth($roster->year, $roster->month);
        } catch (ValidationException $exception) {
            $this->previousNightBoundaryError = $exception->errors()['roster'][0] ?? 'Previous-month Night assignment information is incomplete.';
        }
        $this->shifts = $roster->shifts()->with(['shiftType', 'assignments'])->get()->keyBy('id');
        $this->doctors = Doctor::query()->get()->keyBy('id');
        try {
            $this->monthlyParticipation->assertRosterSnapshotIntegrity($roster);
        } catch (ValidationException) {
            $this->participationSnapshotIntegrityMatches = false;
        }
        if ($this->checkActiveStatus && $this->participationSnapshotIntegrityMatches) {
            try {
                $this->monthlyParticipation->assertRosterPopulationMatchesActiveDoctors($roster, Doctor::query()->where('is_active', true)->get());
            } catch (ValidationException) {
                $this->participationPopulationMatches = false;
            }
        }
        $this->participation = DoctorMonthlyParticipation::query()
            ->where('year', $roster->year)->where('month', $roster->month)->pluck('is_participating', 'doctor_id');
        $first = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $last = $first->endOfMonth();
        $this->excluded = DoctorMonthlyExclusion::query()->where('year', $roster->year)->where('month', $roster->month)->pluck('doctor_id')->flip();
        $this->restrictedShiftTypes = [];
        foreach (DoctorMonthlyShiftRestriction::query()->where('year', $roster->year)->where('month', $roster->month)->get(['doctor_id', 'shift_type_id']) as $restriction) {
            $this->restrictedShiftTypes[$restriction->doctor_id][$restriction->shift_type_id] = true;
        }
        $this->dayOff = DoctorRequest::query()->with('shiftType')->where('request_type', DoctorRequestType::DayOff->value)
            ->whereBetween('request_date', [$first->subDay(), $last->addDay()])->get()->toBase()->groupBy('doctor_id');
        $this->preferred = DoctorRequest::query()->with(['doctor', 'shiftType'])
            ->where('request_type', DoctorRequestType::PreferredWork->value)
            ->whereBetween('request_date', [$first, $last])->get();
        $this->history = $this->planningHistory->forMonth($roster->year, $roster->month, false);
        $previousParticipation = $this->planningHistory->participationForMonth($roster->year, $roster->month, $this->history);
        $monthlyWeekdayPreferences = DoctorMonthlyWeekdayPreference::query()
            ->where('year', $roster->year)->where('month', $roster->month)->get();
        $this->ranker->initialize($this->shifts->values(), $this->preferred, $this->history, $first, $this->restrictedShiftTypes, $monthlyWeekdayPreferences, [], $previousParticipation);
    }

    public function assertParticipationPopulationMatches(): void
    {
        if (! $this->participationPopulationMatches) {
            throw ValidationException::withMessages(['edit' => 'Active doctor statuses no longer match this Draft roster’s saved participation. Restore the active statuses to match the saved population before editing.']);
        }
    }

    /** @param array<string, int> $state
     * @param  list<array{shift: RosterShift, role: RosterAssignmentRole, slot: int}>  $affected
     */
    public function assertFinalDependentNightBoundaryCompatible(Roster $roster, array $state, array $affected): void
    {
        $lastDate = CarbonImmutable::create($roster->year, $roster->month, 1)->endOfMonth();
        $touchesLastNight = collect($affected)->contains(fn (array $slot): bool => $slot['shift']->shift_date->isSameDay($lastDate)
            && $slot['shift']->shiftType->is_overnight);
        if (! $touchesLastNight) {
            return;
        }

        $nightDoctorIds = [];
        foreach ($state as $key => $doctorId) {
            [$shiftId] = explode(':', $key);
            $shift = $this->shifts->get((int) $shiftId);
            if ($shift !== null && $shift->shift_date->isSameDay($lastDate) && $shift->shiftType->is_overnight) {
                $nightDoctorIds[] = $doctorId;
            }
        }

        $this->nightBoundary->assertNoFinalDependentConflict($roster->year, $roster->month, collect(array_unique($nightDoctorIds))->flip(), 'edit');
    }

    public function key(int $shiftId, RosterAssignmentRole $role, int $slot): string
    {
        return "$shiftId:{$role->value}:$slot";
    }

    /** @return array<string, int> */
    public function state(): array
    {
        $state = [];
        foreach ($this->shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $state[$this->key($shift->id, $assignment->role, $assignment->slot_number)] = $assignment->doctor_id;
            }
        }

        return $state;
    }

    public function assignment(int $shiftId, RosterAssignmentRole $role, int $slot): ?RosterAssignment
    {
        $shift = $this->shift($shiftId, $role, $slot);

        return $shift->assignments->first(fn (RosterAssignment $assignment): bool => $assignment->role === $role && $assignment->slot_number === $slot);
    }

    public function shift(int $shiftId, RosterAssignmentRole $role, int $slot): RosterShift
    {
        $shift = $this->shifts->get($shiftId);
        if ($shift === null) {
            abort(404);
        }
        $capacity = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
        if ($slot < 1 || $slot > $capacity) {
            abort(422, 'This slot does not exist for the shift.');
        }

        return $shift;
    }

    /** @param array<string, int> $state
     * @return list<string>
     */
    public function hardReasons(int $doctorId, RosterShift $shift, RosterAssignmentRole $role, array $state): array
    {
        $doctor = $this->doctors->get($doctorId);
        if ($doctor === null) {
            return ['The selected doctor does not exist.'];
        }
        $assignments = [];
        foreach ($state as $key => $assignedDoctorId) {
            if ($assignedDoctorId === $doctorId) {
                [$assignedShiftId, $assignedRole, $assignedSlot] = explode(':', $key);
                $assignedShift = $this->shifts->get((int) $assignedShiftId);
                if ($assignedShift !== null) {
                    $assignments[] = [
                        'shift' => $assignedShift,
                        'role' => RosterAssignmentRole::from($assignedRole),
                        'slot' => (int) $assignedSlot,
                    ];
                }
            }
        }
        $historyNight = $this->history->get($doctorId)?->most_recent_night_shift_at;
        $codes = $this->eligibility->roleAwareConflicts(
            $doctor,
            $shift,
            $role,
            $this->excluded->has($doctorId),
            (bool) $this->participation->get($doctorId, false),
            $this->dayOff->get($doctorId, collect()),
            collect($assignments)->map(fn (array $assignment): array => [
                'shift' => $assignment['shift'],
                'role' => $assignment['role'],
            ]),
            $historyNight,
            $this->checkActiveStatus,
            isset($this->restrictedShiftTypes[$doctorId][$shift->shift_type_id]),
            $this->previousNightDoctorIds,
        );

        if ($shift->shift_date->day === 1 && $this->previousNightDoctorIds === null) {
            $codes[] = 'previous_night_boundary_unknown';
        }
        $isMonthEndNight = $shift->shift_date->isSameDay(CarbonImmutable::create($shift->shift_date->year, $shift->shift_date->month, 1)->endOfMonth())
            && $shift->shiftType->is_overnight;
        if ($isMonthEndNight && $this->finalDependentDoctorIds->has($doctorId)) {
            $codes[] = 'dependent_final_roster_conflict';
        }

        $dayOffRequest = $this->dayOff->get($doctorId, collect())->first(fn (DoctorRequest $request): bool => $this->requestIntervals->overlaps(
            $this->requestIntervals->forDate($shift->shift_date, $shift->shiftType),
            $this->requestIntervals->forRequest($request),
        ));
        $sameDateAssignment = collect($assignments)->first(fn (array $assignment): bool => $assignment['shift']->shift_date->isSameDay($shift->shift_date));
        $previousNightAssignment = collect($assignments)->first(fn (array $assignment): bool => $this->eligibility->roleAwareShiftConflict($shift, $role, $assignment['shift'], $assignment['role']) === 'previous_night_assignment');
        $nightToNightAssignment = collect($assignments)->first(fn (array $assignment): bool => $this->eligibility->roleAwareShiftConflict($shift, $role, $assignment['shift'], $assignment['role']) === 'night_to_night_recovery');
        $overlappingAssignment = collect($assignments)->first(fn (array $assignment): bool => $this->eligibility->roleAwareShiftConflict($shift, $role, $assignment['shift'], $assignment['role']) === 'shift_time_overlap');
        $monthName = $shift->shift_date->format('F');

        return array_map(function (string $code) use ($doctor, $shift, $dayOffRequest, $sameDateAssignment, $previousNightAssignment, $nightToNightAssignment, $overlappingAssignment, $historyNight, $monthName): string {
            return match ($code) {
                'inactive_doctor' => "$doctor->name is inactive and cannot be assigned to the roster.",
                'not_participating' => "$doctor->name is not participating in the $monthName roster.",
                'monthly_exclusion' => "$doctor->name is excluded from the $monthName roster.",
                'monthly_shift_restriction' => "$doctor->name cannot work {$shift->shiftType->name} shifts in $monthName because of a monthly shift restriction.",
                'day_off_overlap' => $this->dayOffMessage($doctor->name, $shift, $dayOffRequest),
                'same_start_date' => $this->sameDateAssignmentMessage($doctor->name, $sameDateAssignment),
                'previous_night_assignment' => $this->previousNightAssignmentMessage($doctor->name, $shift, $previousNightAssignment),
                'previous_night_boundary_unknown' => $this->previousNightBoundaryError ?? 'Previous-month Night assignment information is incomplete. Complete Initial Setup before assigning this date.',
                'dependent_final_roster_conflict' => "$doctor->name already has an assignment on the following date in the finalized next-month roster.",
                'night_to_night_recovery' => $this->nightToNightRecoveryMessage($doctor->name, $shift, $nightToNightAssignment, $historyNight),
                'shift_time_overlap' => $this->shiftTimeOverlapMessage($doctor->name, $overlappingAssignment),
                default => "$doctor->name has a scheduling conflict ($code).",
            };
        }, $codes);
    }

    /** @param array{shift: RosterShift, role: RosterAssignmentRole, slot: int}|null $assignment */
    private function shiftTimeOverlapMessage(string $doctorName, ?array $assignment): string
    {
        if ($assignment === null) {
            return "$doctorName has another assignment that overlaps this shift.";
        }

        $date = $assignment['shift']->shift_date->format('M j');
        $shiftName = $assignment['shift']->shiftType->name;
        $role = ucfirst($assignment['role']->value);
        $slot = $assignment['slot'];

        return "$doctorName has an overlapping $shiftName assignment on $date ($role Slot $slot).";
    }

    /** @param array{shift: RosterShift, role: RosterAssignmentRole, slot: int}|null $assignment */
    private function previousNightAssignmentMessage(string $doctorName, RosterShift $shift, ?array $assignment): string
    {
        if ($assignment !== null) {
            $nightDate = $assignment['shift']->shiftType->is_overnight
                ? $assignment['shift']->shift_date
                : $shift->shift_date;
            $followingDate = $nightDate->copy()->addDay();

            return "$doctorName has Night duty on {$nightDate->format('M j')} and cannot receive any assignment on {$followingDate->format('M j')}, including Optional duties.";
        }

        $previousDate = $shift->shift_date->copy()->subDay();

        return "$doctorName has Night duty on {$previousDate->format('M j')} and cannot receive any assignment on {$shift->shift_date->format('M j')}, including Optional duties.";
    }

    private function dayOffMessage(string $doctorName, RosterShift $shift, ?DoctorRequest $request): string
    {
        $date = $request?->request_date->format('M j') ?? $shift->shift_date->format('M j');
        $requestDescription = $request?->shiftType === null ? 'time off' : "{$request->shiftType->name} off";

        return "$doctorName requested $requestDescription on $date. This shift overlaps that request.";
    }

    /** @param array{shift: RosterShift, role: RosterAssignmentRole, slot: int}|null $assignment */
    private function sameDateAssignmentMessage(string $doctorName, ?array $assignment): string
    {
        if ($assignment === null) {
            return "$doctorName is already assigned to another shift on this date. A doctor can only work one shift per day.";
        }

        $date = $assignment['shift']->shift_date->format('M j');
        $shiftName = $assignment['shift']->shiftType->name;
        $role = ucfirst($assignment['role']->value);
        $slot = $assignment['slot'];

        return "$doctorName is already assigned to $date - $shiftName ($role Slot $slot). A doctor can only work one shift per day.";
    }

    /** @param array{shift: RosterShift, role: RosterAssignmentRole, slot: int}|null $nightAssignment */
    private function nextDayNightRecoveryMessage(string $doctorName, RosterShift $shift, ?array $nightAssignment, ?CarbonInterface $historyNight): string
    {
        if ($nightAssignment !== null) {
            $assignedShift = $nightAssignment['shift'];
            $date = $assignedShift->shift_date->format('M j');
            $shiftName = $assignedShift->shiftType->name;
            $role = ucfirst($nightAssignment['role']->value);
            $slot = $nightAssignment['slot'];

            if ($assignedShift->shiftType->is_overnight && $assignedShift->shift_date->lt($shift->shift_date)) {
                return "$doctorName worked a $shiftName shift on $date ($role Slot $slot) and needs a rest day before another shift.";
            }

            return "$doctorName has a $shiftName assignment on $date ($role Slot $slot), immediately after this Night shift. A rest day is required between duties.";
        }

        if ($historyNight !== null) {
            return "$doctorName worked a Night shift on {$historyNight->format('M j')} and needs a rest day before another shift.";
        }

        return "$doctorName needs a rest day between this shift and the conflicting Night duty.";
    }

    /** @param array{shift: RosterShift, role: RosterAssignmentRole, slot: int}|null $nightAssignment */
    private function nightToNightRecoveryMessage(string $doctorName, RosterShift $shift, ?array $nightAssignment, ?CarbonInterface $historyNight): string
    {
        if ($nightAssignment !== null) {
            $assignedShift = $nightAssignment['shift'];
            $date = $assignedShift->shift_date->format('M j');
            $shiftName = $assignedShift->shiftType->name;
            $role = ucfirst($nightAssignment['role']->value);
            $slot = $nightAssignment['slot'];

            return "$doctorName has a $shiftName assignment on $date ($role Slot $slot), too close to this Night shift. Leave a rest day between Night duties.";
        }

        $otherNightDate = $historyNight;
        $dates = collect([$shift->shift_date, $otherNightDate])
            ->filter()
            ->sortBy(fn (CarbonInterface $date): int => $date->timestamp)
            ->map(fn (CarbonInterface $date): string => $date->format('M j'))
            ->values();
        $dateMessage = $dates->count() === 2 ? ' on '.$dates->join(' and ') : '';

        return "$doctorName has Night duties$dateMessage that are too close together. Leave a rest day between Night shifts.";
    }

    /** @param array<string, int> $state
     * @return list<int>
     */
    public function dimensions(int $doctorId, RosterShift $shift, RosterAssignmentRole $role, array $state): array
    {
        $assigned = $this->rankingAssignedShifts($doctorId, $state);

        return $this->ranker->dimensions($this->doctors->get($doctorId), $shift, $role, $assigned);
    }

    /** @param array<string, int> $state
     * @return list<RosterShift>
     */
    public function blockedPreferredShifts(int $doctorId, RosterShift $shift, RosterAssignmentRole $role, array $state): array
    {
        $assigned = $this->rankingAssignedShifts($doctorId, $state);
        $blocked = $this->ranker->blockedPreferredShifts($this->doctors->get($doctorId), $shift, $role, $assigned);

        return array_values(array_filter($blocked, fn (RosterShift $preferredShift): bool => $this->hardReasons($doctorId, $preferredShift, RosterAssignmentRole::Main, $state) === []));
    }

    /** @param array<string, int> $state
     * @return Collection<int, array{shift: RosterShift, role: RosterAssignmentRole}>
     */
    private function rankingAssignedShifts(int $doctorId, array $state): Collection
    {
        $this->ranker->resetRecordedAssignments();
        $assigned = collect();
        foreach ($state as $key => $assignedDoctorId) {
            $parts = explode(':', $key);
            $assignedShift = $this->shifts->get((int) $parts[0]);
            if ($assignedShift === null) {
                continue;
            }
            $this->ranker->record($assignedDoctorId, $assignedShift, RosterAssignmentRole::from($parts[1]));
            if ($assignedDoctorId === $doctorId) {
                $assigned->push(['shift' => $assignedShift, 'role' => RosterAssignmentRole::from($parts[1])]);
            }
        }

        return $assigned;
    }

    /** @param array<string, int> $state
     * @return array{effective_workload_minutes: int, night_count: int, optional_count: int}
     */
    public function metrics(int $doctorId, array $state): array
    {
        $history = $this->history->has($doctorId) ? $this->history->get($doctorId) : null;
        $minutes = $history === null ? 0 : $history->closing_balance_minutes;
        $nights = $history === null ? 0 : $history->actual_night_duty_count;
        $optional = $history === null ? 0 : $history->optional_assignment_count;
        foreach ($state as $key => $assignedDoctorId) {
            if ($assignedDoctorId !== $doctorId) {
                continue;
            }
            $parts = explode(':', $key);
            $shift = $this->shifts->get((int) $parts[0]);
            if ($shift === null) {
                continue;
            }
            if ($parts[1] === RosterAssignmentRole::Optional->value) {
                $optional++;
            } else {
                $minutes += $shift->shiftType->duration_minutes;
                if ($shift->shiftType->is_overnight) {
                    $nights++;
                }
            }
        }

        return ['effective_workload_minutes' => $minutes, 'night_count' => $nights, 'optional_count' => $optional];
    }

    public function prefers(int $doctorId, RosterShift $shift, RosterAssignmentRole $role = RosterAssignmentRole::Main): bool
    {
        if ($role !== RosterAssignmentRole::Main || isset($this->restrictedShiftTypes[$doctorId][$shift->shift_type_id])) {
            return false;
        }

        return $this->preferred->contains(fn (DoctorRequest $request): bool => $request->doctor_id === $doctorId
            && $request->request_date->isSameDay($shift->shift_date) && $request->shift_type_id === $shift->shift_type_id);
    }

    /** @param array<string, int> $state */
    public function preferenceFulfilled(DoctorRequest $request, array $state): bool
    {
        foreach ($this->shifts as $shift) {
            if ($shift->shift_type_id !== $request->shift_type_id || ! $shift->shift_date->isSameDay($request->request_date)) {
                continue;
            }
            for ($slot = 1; $slot <= $shift->shiftType->main_count; $slot++) {
                if (($state[$this->key($shift->id, RosterAssignmentRole::Main, $slot)] ?? null) === $request->doctor_id) {
                    return true;
                }
            }
        }

        return false;
    }
}
