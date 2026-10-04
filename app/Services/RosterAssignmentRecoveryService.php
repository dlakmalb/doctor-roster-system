<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\RosterShift;
use Illuminate\Support\Collection;

class RosterAssignmentRecoveryService
{
    private const MAX_CHAIN_DEPTH = 5;

    private const MAX_EXPLORED_STATES = 240;

    /** @var array<string, array{shift: RosterShift, role: RosterAssignmentRole, slot: int, doctor_id: int, fixed: bool}> */
    private array $plan = [];

    /** @var Collection<int, Doctor> */
    private Collection $doctors;

    /** @var Collection<int, int> */
    private Collection $excludedDoctorIds;

    /** @var Collection<int|string, Collection<int, DoctorRequest>> */
    private Collection $dayOffRequests;

    /** @var Collection<int, DoctorMonthlyWorkload> */
    private Collection $previousHistory;

    private RosterCandidateRanker $ranker;

    public function __construct(private DoctorAssignmentEligibilityService $eligibility) {}

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, int>  $excludedDoctorIds
     * @param  Collection<int|string, Collection<int, DoctorRequest>>  $dayOffRequests
     * @param  Collection<int, DoctorMonthlyWorkload>  $previousHistory
     * @return list<array{roster_shift_id: int, doctor_id: int, role: RosterAssignmentRole, slot_number: int}>
     */
    public function plan(Collection $shifts, Collection $doctors, Collection $excludedDoctorIds, Collection $dayOffRequests, Collection $previousHistory, RosterCandidateRanker $ranker): array
    {
        $this->ranker = $ranker;
        $this->plan = [];
        $this->doctors = $doctors;
        $this->excludedDoctorIds = $excludedDoctorIds;
        $this->dayOffRequests = $dayOffRequests;
        $this->previousHistory = $previousHistory;

        foreach ($shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $key = $this->key($shift, $assignment->role, $assignment->slot_number);
                $this->plan[$key] = ['shift' => $shift, 'role' => $assignment->role, 'slot' => $assignment->slot_number, 'doctor_id' => $assignment->doctor_id, 'fixed' => true];
            }
        }

        $stages = [
            $shifts->filter(fn (RosterShift $shift): bool => $this->ranker->mainStage($shift) === 0),
            $shifts->filter(fn (RosterShift $shift): bool => $this->ranker->mainStage($shift) === 1),
            $shifts->filter(fn (RosterShift $shift): bool => $this->ranker->mainStage($shift) === 2),
            $shifts->filter(fn (RosterShift $shift): bool => $this->ranker->mainStage($shift) === 3),
        ];

        foreach ($stages as $stage) {
            $this->fill($stage, RosterAssignmentRole::Main);
        }
        $this->fill($shifts, RosterAssignmentRole::Optional);

        $newAssignments = [];
        foreach ($this->plan as $entry) {
            if (! $entry['fixed']) {
                $newAssignments[] = [
                    'roster_shift_id' => $entry['shift']->id,
                    'doctor_id' => $entry['doctor_id'],
                    'role' => $entry['role'],
                    'slot_number' => $entry['slot'],
                ];
            }
        }

        return $newAssignments;
    }

    /** @param Collection<int, RosterShift> $shifts */
    private function fill(Collection $shifts, RosterAssignmentRole $role): void
    {
        $orderedShifts = $shifts->sortBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString().' '.$shift->shiftType->start_time);
        foreach ($orderedShifts as $shift) {
            $required = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
            for ($slot = 1; $slot <= $required; $slot++) {
                $key = $this->key($shift, $role, $slot);
                if (isset($this->plan[$key])) {
                    continue;
                }

                $explored = 0;
                $visited = [];
                if (! $this->place($shift, $role, $slot, 0, $explored, $visited, false)) {
                    $explored = 0;
                    $visited = [];
                    $this->place($shift, $role, $slot, 0, $explored, $visited, true);
                }
            }
        }
    }

    /** @param array<string, true> $visited */
    private function place(RosterShift $shift, RosterAssignmentRole $role, int $slot, int $depth, int &$explored, array &$visited, bool $allowReassignment): bool
    {
        if ($depth > self::MAX_CHAIN_DEPTH || $explored >= self::MAX_EXPLORED_STATES) {
            return false;
        }

        $targetKey = $this->key($shift, $role, $slot);
        $signatureEntries = $this->plan;
        ksort($signatureEntries);
        $signature = $targetKey.'|'.implode(',', array_map(
            fn (string $key, array $entry): string => $key.':'.$entry['doctor_id'],
            array_keys($signatureEntries),
            array_values($signatureEntries),
        ));
        if (isset($visited[$signature])) {
            return false;
        }
        $visited[$signature] = true;
        $explored++;

        $assignedByDoctor = $this->assignedByDoctor();
        $this->rebuildRanking();
        foreach ($this->ranker->ordered($this->doctors, $shift, $role, $assignedByDoctor) as $doctor) {
            $blockers = [];
            foreach ($this->plan as $key => $entry) {
                if ($entry['doctor_id'] === $doctor->id && $this->eligibility->shiftConflict($shift, $entry['shift']) !== null) {
                    $blockers[$key] = $entry;
                }
            }

            if (count($blockers) + $depth > self::MAX_CHAIN_DEPTH) {
                continue;
            }
            if (! $allowReassignment && $blockers !== []) {
                continue;
            }
            if (collect($blockers)->contains(fn (array $entry): bool => $entry['fixed'] || $entry['role'] !== $role)) {
                continue;
            }

            $before = $this->plan;
            foreach (array_keys($blockers) as $key) {
                unset($this->plan[$key]);
            }
            $doctorShifts = $this->assignedByDoctor()->get($doctor->id, collect());
            $history = $this->previousHistory->get($doctor->id);
            if ($this->eligibility->conflicts($doctor, $shift, $this->excludedDoctorIds->has($doctor->id), $this->dayOffRequests->get($doctor->id, collect()), $doctorShifts, $history?->most_recent_night_shift_at) !== []) {
                $this->plan = $before;

                continue;
            }

            $this->plan[$targetKey] = ['shift' => $shift, 'role' => $role, 'slot' => $slot, 'doctor_id' => $doctor->id, 'fixed' => false];
            $resolved = true;
            foreach ($blockers as $entry) {
                if (! $this->place($entry['shift'], $entry['role'], $entry['slot'], $depth + 1, $explored, $visited, true)) {
                    $resolved = false;
                    break;
                }
            }
            if ($resolved) {
                return true;
            }
            $this->plan = $before;
        }

        return false;
    }

    /** @return Collection<int, Collection<int, RosterShift>> */
    private function assignedByDoctor(): Collection
    {
        $assigned = collect();
        foreach ($this->plan as $entry) {
            if (! $assigned->has($entry['doctor_id'])) {
                $assigned->put($entry['doctor_id'], collect());
            }
            $assigned->get($entry['doctor_id'])->push($entry['shift']);
        }

        return $assigned;
    }

    private function rebuildRanking(): void
    {
        $this->ranker->resetRecordedAssignments();
        foreach ($this->plan as $entry) {
            $this->ranker->record($entry['doctor_id'], $entry['shift'], $entry['role']);
        }
    }

    private function key(RosterShift $shift, RosterAssignmentRole $role, int $slot): string
    {
        return $shift->id.':'.$role->value.':'.$slot;
    }
}
