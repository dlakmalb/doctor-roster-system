<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\RosterShift;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class RosterAssignmentRecoveryService
{
    private const MAX_CHAIN_DEPTH = 5;

    private const MAX_EXPLORED_STATES = 240;

    /** @var array<string, array<string, int>> */
    private array $unfilledDiagnostics = [];

    /** @var array<string, int> */
    private array $searchFailures = [];

    /** @var array<string, int|float> */
    private array $diagnostics = [];

    /** @var array<string, array<string, int|float>> */
    private array $vacancyDiagnostics = [];

    /** @var array<string, array<int, array<int, true>>> */
    private array $staticallyEligible = [];

    /** @var array<string, array<string, array<int, bool>>> */
    private array $conflictingShifts = [];

    /** @var array<string, array<int, array<int, list<int>>>> */
    private array $conflictingShiftIds = [];

    /** @var array<int, RosterShift> */
    private array $shiftsById = [];

    /** @var array<string, array{shift: RosterShift, role: RosterAssignmentRole, slot: int, doctor_id: int, fixed: bool}> */
    private array $plan = [];

    /** @var Collection<int, Doctor> */
    private Collection $doctors;

    private RosterCandidateRanker $ranker;

    public function __construct(private DoctorAssignmentEligibilityService $eligibility) {}

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, int>  $excludedDoctorIds
     * @param  Collection<int|string, Collection<int, DoctorRequest>>  $dayOffRequests
     * @param  Collection<int, DoctorMonthlyWorkload>  $previousHistory
     * @param  array<int, array<int, true>>  $restrictedShiftTypes
     * @param  Collection<int, bool>  $participationSnapshot
     * @return list<array{roster_shift_id: int, doctor_id: int, role: RosterAssignmentRole, slot_number: int}>
     */
    public function plan(Collection $shifts, Collection $doctors, Collection $excludedDoctorIds, Collection $dayOffRequests, Collection $previousHistory, RosterCandidateRanker $ranker, array $restrictedShiftTypes, Collection $participationSnapshot, ?Collection $previousNightDoctorIds = null): array
    {
        $this->ranker = $ranker;
        $this->plan = [];
        $this->unfilledDiagnostics = [];
        $this->diagnostics = ['calls' => 0, 'states' => 0, 'max_depth' => 0, 'candidates' => 0, 'chains' => 0, 'ranking_seconds' => 0.0, 'elapsed_seconds' => 0.0, 'chain_depth_limit' => 0, 'explored_state_limit' => 0, 'fixed_blockers' => 0, 'no_eligible_candidate' => 0];
        $this->vacancyDiagnostics = [];
        $this->staticallyEligible = $this->conflictingShifts = [];
        $this->conflictingShiftIds = [];
        $this->shiftsById = $shifts->keyBy('id')->all();
        $this->doctors = $doctors;
        foreach ($shifts as $shift) {
            try {
                $capacity = $shift->savedCapacity();
            } catch (LogicException $exception) {
                throw ValidationException::withMessages(['roster' => $exception->getMessage()]);
            }
            foreach ($shift->assignments as $assignment) {
                $limit = $assignment->role === RosterAssignmentRole::Main ? $capacity->main_count : $capacity->optional_count;
                if ($assignment->slot_number < 1 || $assignment->slot_number > $limit) {
                    throw ValidationException::withMessages(['roster' => "Roster shift {$shift->id} has an assignment outside its saved {$assignment->role->value} capacity."]);
                }
            }
            foreach ($doctors as $doctor) {
                $history = $previousHistory->get($doctor->id);
                foreach (RosterAssignmentRole::cases() as $role) {
                    $conflicts = $this->eligibility->roleAwareConflicts(
                        $doctor,
                        $shift,
                        $role,
                        $excludedDoctorIds->has($doctor->id),
                        $participationSnapshot->get($doctor->id, false),
                        $dayOffRequests->get($doctor->id, collect()),
                        collect(),
                        $history?->most_recent_night_shift_at,
                        true,
                        isset($restrictedShiftTypes[$doctor->id][$shift->shift_type_id]),
                        $previousNightDoctorIds,
                    );
                    if ($conflicts === []) {
                        $this->staticallyEligible[$role->value][$shift->id][$doctor->id] = true;
                    }
                }
            }
        }

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

        $this->assertPreviousNightRule($previousNightDoctorIds ?? collect());

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

    /** @param Collection<int, true> $previousNightDoctorIds */
    private function assertPreviousNightRule(Collection $previousNightDoctorIds): void
    {
        $entriesByDoctor = [];
        foreach ($this->plan as $entry) {
            if ($entry['shift']->shift_date->day === 1 && $previousNightDoctorIds->has($entry['doctor_id'])) {
                throw ValidationException::withMessages([
                    'roster' => "{$entry['shift']->shift_date->format('F j')}: a doctor assigned to the previous month's final Night shift cannot be assigned on this date. Correct the fixed assignment before generating.",
                ]);
            }
            $entriesByDoctor[$entry['doctor_id']][] = $entry;
        }

        foreach ($entriesByDoctor as $entries) {
            for ($first = 0; $first < count($entries); $first++) {
                for ($second = $first + 1; $second < count($entries); $second++) {
                    if ($this->eligibility->roleAwareShiftConflict(
                        $entries[$first]['shift'],
                        $entries[$first]['role'],
                        $entries[$second]['shift'],
                        $entries[$second]['role'],
                    ) === 'previous_night_assignment') {
                        throw ValidationException::withMessages([
                            'roster' => "{$entries[$first]['shift']->shift_date->format('M j')} Night and {$entries[$second]['shift']->shift_date->format('M j')} assignments conflict. Correct the fixed assignments before generating.",
                        ]);
                    }
                }
            }
        }
    }

    /** @return array<string, array<string, int>> */
    public function unfilledDiagnostics(): array
    {
        return array_filter($this->unfilledDiagnostics, fn (string $key): bool => ! isset($this->plan[$key]), ARRAY_FILTER_USE_KEY);
    }

    /** @return array{totals: array<string, int|float>, vacancies: array<string, array<string, int|float>>} */
    public function diagnostics(): array
    {
        return ['totals' => $this->diagnostics, 'vacancies' => $this->vacancyDiagnostics];
    }

    /** @param Collection<int, RosterShift> $shifts */
    private function fill(Collection $shifts, RosterAssignmentRole $role): void
    {
        $orderedShifts = $shifts->sortBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString().' '.$shift->shiftType->start_time);
        foreach ($orderedShifts as $shift) {
            $required = $shift->savedCapacityForRole($role);
            for ($slot = 1; $slot <= $required; $slot++) {
                $key = $this->key($shift, $role, $slot);
                if (isset($this->plan[$key])) {
                    continue;
                }

                $explored = 0;
                $visited = [];
                $this->searchFailures = [];
                $started = microtime(true);
                $before = $this->diagnostics;
                $this->diagnostics['calls']++;
                if (! $this->place($shift, $role, $slot, 0, $explored, $visited, false)) {
                    $explored = 0;
                    $visited = [];
                    $this->diagnostics['calls']++;
                    if (! $this->place($shift, $role, $slot, 0, $explored, $visited, true)) {
                        $this->unfilledDiagnostics[$key] = $this->searchFailures;
                    }
                }
                $this->diagnostics['elapsed_seconds'] += microtime(true) - $started;
                $this->vacancyDiagnostics[$key] = [
                    'calls' => $this->diagnostics['calls'] - $before['calls'],
                    'states' => $this->diagnostics['states'] - $before['states'],
                    'candidates' => $this->diagnostics['candidates'] - $before['candidates'],
                    'chains' => $this->diagnostics['chains'] - $before['chains'],
                    'elapsed_seconds' => $this->diagnostics['elapsed_seconds'] - $before['elapsed_seconds'],
                    'chain_depth_limit' => $this->diagnostics['chain_depth_limit'] - $before['chain_depth_limit'],
                    'explored_state_limit' => $this->diagnostics['explored_state_limit'] - $before['explored_state_limit'],
                    'fixed_blockers' => $this->diagnostics['fixed_blockers'] - $before['fixed_blockers'],
                    'no_eligible_candidate' => $this->diagnostics['no_eligible_candidate'] - $before['no_eligible_candidate'],
                ];
            }
        }
    }

    /** @param array<string, int> $visited */
    private function place(RosterShift $shift, RosterAssignmentRole $role, int $slot, int $depth, int &$explored, array &$visited, bool $allowReassignment): bool
    {
        $this->diagnostics['max_depth'] = max($this->diagnostics['max_depth'], $depth);
        if ($depth > self::MAX_CHAIN_DEPTH) {
            $this->recordFailure('chain_depth_limit');

            return false;
        }
        if ($explored >= self::MAX_EXPLORED_STATES) {
            $this->recordFailure('explored_state_limit');

            return false;
        }

        $targetKey = $this->key($shift, $role, $slot);
        if (isset($this->plan[$targetKey])) {
            return true;
        }

        if ($depth === 0) {
            $this->rebuildRanking();
        }

        $signatureEntries = $this->plan;
        ksort($signatureEntries);
        $signature = $targetKey.'|'.implode(',', array_map(
            fn (string $key, array $entry): string => $key.':'.$entry['doctor_id'],
            array_keys($signatureEntries),
            array_values($signatureEntries),
        ));
        if (isset($visited[$signature]) && $visited[$signature] <= $depth) {
            return false;
        }
        $visited[$signature] = $depth;
        $explored++;
        $this->diagnostics['states']++;

        $entriesByDoctor = [];
        $assignedByDoctor = $this->assignedByDoctor($entriesByDoctor);
        $eligibleCandidateFound = false;
        $eligibleDoctors = $this->doctors->filter(fn (Doctor $doctor): bool => isset($this->staticallyEligible[$role->value][$shift->id][$doctor->id]));
        $candidates = [];
        $orderedDoctors = $this->ranker->ordered($eligibleDoctors, $shift, $role, $assignedByDoctor);
        foreach ($orderedDoctors as $ranking => $doctor) {
            if (! $allowReassignment && $this->ranker->isScheduledGroupCandidate($doctor, $shift, $role) === false) {
                continue;
            }
            $this->diagnostics['candidates']++;
            $blockers = [];
            foreach (RosterAssignmentRole::cases() as $existingRole) {
                foreach ($this->conflictingShiftIds($shift, $role, $existingRole) as $conflictingShiftId) {
                    $entries = $entriesByDoctor[$doctor->id][$conflictingShiftId] ?? null;
                    if ($entries === null) {
                        continue;
                    }
                    if (isset($entries['shift'])) {
                        if ($entries['role'] === $existingRole) {
                            $blockerKey = $this->key($entries['shift'], $entries['role'], $entries['slot']);
                            $blockers[$blockerKey] = $entries;
                        }

                        continue;
                    }
                    foreach ($entries as $blockerKey => $entry) {
                        if ($entry['role'] === $existingRole) {
                            $blockers[$blockerKey] = $entry;
                        }
                    }
                }
            }
            if (! $allowReassignment && $blockers !== []) {
                continue;
            }
            if (count($blockers) + $depth > self::MAX_CHAIN_DEPTH) {
                $this->recordFailure('chain_depth_limit');

                continue;
            }
            $hasFixedBlocker = false;
            foreach ($blockers as $entry) {
                if ($entry['fixed']) {
                    $hasFixedBlocker = true;
                    break;
                }
            }
            if ($hasFixedBlocker) {
                $this->recordFailure('fixed_blockers');

                continue;
            }

            $candidates[] = ['doctor' => $doctor, 'blockers' => $blockers, 'ranking' => $ranking];
            if (! $allowReassignment) {
                break;
            }
        }
        usort($candidates, function (array $left, array $right) use ($shift, $role): int {
            $leftGroupMatch = $this->ranker->isScheduledGroupCandidate($left['doctor'], $shift, $role);
            $rightGroupMatch = $this->ranker->isScheduledGroupCandidate($right['doctor'], $shift, $role);
            if ($leftGroupMatch !== null && $rightGroupMatch !== null && $leftGroupMatch !== $rightGroupMatch) {
                return $leftGroupMatch ? -1 : 1;
            }

            return count($left['blockers']) <=> count($right['blockers']) ?: $left['ranking'] <=> $right['ranking'];
        });

        foreach ($candidates as $candidate) {
            $doctor = $candidate['doctor'];
            $blockers = $candidate['blockers'];

            $before = $this->plan;
            $rankingBefore = $this->ranker->recordedAssignmentsSnapshot();
            foreach (array_keys($blockers) as $key) {
                $entry = $this->plan[$key];
                $this->ranker->unrecord($entry['doctor_id'], $entry['shift'], $entry['role']);
                unset($this->plan[$key]);
            }

            $eligibleCandidateFound = true;
            $this->plan[$targetKey] = ['shift' => $shift, 'role' => $role, 'slot' => $slot, 'doctor_id' => $doctor->id, 'fixed' => false];
            $this->ranker->record($doctor->id, $shift, $role);
            $resolved = true;
            if (count($blockers) > 1) {
                uasort($blockers, fn (array $left, array $right): int => $this->eligibleCandidateCount($left['role'], $left['shift']) <=> $this->eligibleCandidateCount($right['role'], $right['shift']) ?: strcmp($this->key($left['shift'], $left['role'], $left['slot']), $this->key($right['shift'], $right['role'], $right['slot'])));
            }
            if ($blockers !== []) {
                $this->diagnostics['chains']++;
            }
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
            $this->ranker->restoreRecordedAssignments($rankingBefore);
        }

        if (! $eligibleCandidateFound) {
            $this->recordFailure('no_eligible_candidate');
        }

        return false;
    }

    private function recordFailure(string $reason): void
    {
        $this->searchFailures[$reason] = ($this->searchFailures[$reason] ?? 0) + 1;
        $this->diagnostics[$reason] = ($this->diagnostics[$reason] ?? 0) + 1;
    }

    private function eligibleCandidateCount(RosterAssignmentRole $role, RosterShift $shift): int
    {
        return count($this->staticallyEligible[$role->value][$shift->id] ?? []);
    }

    /**
     * @param  array<int, array<int, array{shift: RosterShift, role: RosterAssignmentRole, slot: int, doctor_id: int, fixed: bool}|array<string, array{shift: RosterShift, role: RosterAssignmentRole, slot: int, doctor_id: int, fixed: bool}>>>  $entriesByDoctor
     * @return Collection<int, Collection<int, array{shift: RosterShift, role: RosterAssignmentRole}>>
     */
    private function assignedByDoctor(array &$entriesByDoctor): Collection
    {
        $assigned = collect();
        foreach ($this->plan as $key => $entry) {
            $shiftId = $entry['shift']->id;
            if (! isset($entriesByDoctor[$entry['doctor_id']][$shiftId])) {
                $entriesByDoctor[$entry['doctor_id']][$shiftId] = $entry;
            } elseif (isset($entriesByDoctor[$entry['doctor_id']][$shiftId]['shift'])) {
                $existingEntry = $entriesByDoctor[$entry['doctor_id']][$shiftId];
                $existingKey = $this->key($existingEntry['shift'], $existingEntry['role'], $existingEntry['slot']);
                $entriesByDoctor[$entry['doctor_id']][$shiftId] = [$existingKey => $existingEntry, $key => $entry];
            } else {
                $entriesByDoctor[$entry['doctor_id']][$shiftId][$key] = $entry;
            }
            if (! $assigned->has($entry['doctor_id'])) {
                $assigned->put($entry['doctor_id'], collect());
            }
            $assigned->get($entry['doctor_id'])->push(['shift' => $entry['shift'], 'role' => $entry['role']]);
        }

        return $assigned;
    }

    private function shiftsConflict(RosterShift $shift, RosterAssignmentRole $role, RosterShift $otherShift, RosterAssignmentRole $otherRole): bool
    {
        $cacheKey = $role->value.':'.$otherRole->value;
        if (! isset($this->conflictingShifts[$cacheKey][$shift->id][$otherShift->id])) {
            $this->conflictingShifts[$cacheKey][$shift->id][$otherShift->id] = $this->eligibility->roleAwareShiftConflict($shift, $role, $otherShift, $otherRole) !== null;
        }

        return $this->conflictingShifts[$cacheKey][$shift->id][$otherShift->id];
    }

    /** @return list<int> */
    private function conflictingShiftIds(RosterShift $shift, RosterAssignmentRole $role, RosterAssignmentRole $otherRole): array
    {
        $cacheKey = $role->value.':'.$otherRole->value;
        if (! isset($this->conflictingShiftIds[$cacheKey][$shift->id])) {
            $conflictingShiftIds = [];
            foreach ($this->shiftsById as $otherShift) {
                if ($this->shiftsConflict($shift, $role, $otherShift, $otherRole)) {
                    $conflictingShiftIds[] = $otherShift->id;
                }
            }
            $this->conflictingShiftIds[$cacheKey][$shift->id] = $conflictingShiftIds;
        }

        return $this->conflictingShiftIds[$cacheKey][$shift->id];
    }

    private function rebuildRanking(): void
    {
        $started = microtime(true);
        $this->ranker->resetRecordedAssignments();
        foreach ($this->plan as $entry) {
            $this->ranker->record($entry['doctor_id'], $entry['shift'], $entry['role']);
        }
        $this->diagnostics['ranking_seconds'] += microtime(true) - $started;
    }

    private function key(RosterShift $shift, RosterAssignmentRole $role, int $slot): string
    {
        return $shift->id.':'.$role->value.':'.$slot;
    }
}
