<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;
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

    /** @var Collection<int, bool> */
    private Collection $participation;

    public bool $participationPopulationMatches = true;

    public bool $participationSnapshotIntegrityMatches = true;

    private bool $checkActiveStatus = true;

    public function __construct(private DoctorAssignmentEligibilityService $eligibility, private RosterCandidateRanker $ranker, private RosterPlanningHistoryService $planningHistory, private DoctorMonthlyParticipationService $monthlyParticipation) {}

    public function load(Roster $roster): void
    {
        $this->participationPopulationMatches = true;
        $this->participationSnapshotIntegrityMatches = true;
        $this->checkActiveStatus = $roster->status === RosterStatus::Draft;
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
        $this->dayOff = DoctorRequest::query()->with('shiftType')->where('request_type', DoctorRequestType::DayOff->value)
            ->whereBetween('request_date', [$first->subDay(), $last->addDay()])->get()->toBase()->groupBy('doctor_id');
        $this->preferred = DoctorRequest::query()->with(['doctor', 'shiftType'])
            ->where('request_type', DoctorRequestType::PreferredWork->value)
            ->whereBetween('request_date', [$first, $last])->get();
        $this->history = $this->planningHistory->forMonth($roster->year, $roster->month, false);
        $this->ranker->initialize($this->shifts->values(), $this->preferred, $this->history, $first);
    }

    public function assertParticipationPopulationMatches(): void
    {
        if (! $this->participationPopulationMatches) {
            throw ValidationException::withMessages(['edit' => 'Active doctor statuses no longer match this Draft roster’s saved participation. Restore the active statuses to match the saved population before editing.']);
        }
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
    public function hardReasons(int $doctorId, RosterShift $shift, array $state): array
    {
        $doctor = $this->doctors->get($doctorId);
        if ($doctor === null) {
            return ['The selected doctor does not exist.'];
        }
        if (! $this->participation->get($doctorId, false)) {
            return ["$doctor->name is not part of the team for this month."];
        }
        $assigned = collect();
        foreach ($state as $key => $assignedDoctorId) {
            if ($assignedDoctorId === $doctorId) {
                $assignedShift = $this->shifts->get((int) explode(':', $key)[0]);
                if ($assignedShift !== null) {
                    $assigned->push($assignedShift);
                }
            }
        }
        $historyNight = $this->history->get($doctorId)?->most_recent_night_shift_at;
        $codes = $this->eligibility->conflicts($doctor, $shift, $this->excluded->has($doctorId), $this->dayOff->get($doctorId, collect()), $assigned, $historyNight, $this->checkActiveStatus);

        return array_map(fn (string $code): string => match ($code) {
            'inactive_doctor' => "$doctor->name is inactive.",
            'monthly_exclusion' => "$doctor->name is excluded for this month.",
            'day_off_overlap' => "$doctor->name has a Day-Off request overlapping this shift.",
            'same_start_date' => "$doctor->name is already assigned to a shift starting on this date.",
            'next_day_night_recovery' => "$doctor->name is unavailable due to next-day Night recovery.",
            'night_to_night_recovery' => "$doctor->name is unavailable due to Night-to-Night recovery.",
            default => "$doctor->name has a scheduling conflict ($code).",
        }, $codes);
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

        return array_values(array_filter($blocked, fn (RosterShift $preferredShift): bool => $this->hardReasons($doctorId, $preferredShift, $state) === []));
    }

    /** @param array<string, int> $state
     * @return Collection<int, RosterShift>
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
                $assigned->push($assignedShift);
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

    public function prefers(int $doctorId, RosterShift $shift): bool
    {
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
