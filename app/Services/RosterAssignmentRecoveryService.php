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

    /** @var array<string, array<string, int>> */
    private array $unfilledDiagnostics = [];

    /** @var array<string, int> */
    private array $searchFailures = [];

    /** @var array<string, int|float> */
    private array $diagnostics = [];

    /** @var array<string, array<string, int|float>> */
    private array $vacancyDiagnostics = [];

    /** @var array<int, array<int, true>> */
    private array $staticallyEligible = [];

    /** @var array<int, array<int, bool>> */
    private array $conflictingShifts = [];

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
     * @return list<array{roster_shift_id: int, doctor_id: int, role: RosterAssignmentRole, slot_number: int}>
     */
    public function plan(Collection $shifts, Collection $doctors, Collection $excludedDoctorIds, Collection $dayOffRequests, Collection $previousHistory, RosterCandidateRanker $ranker): array
    {
        $this->ranker = $ranker;
        $this->plan = [];
        $this->unfilledDiagnostics = [];
        $this->diagnostics = ['calls' => 0, 'states' => 0, 'max_depth' => 0, 'candidates' => 0, 'chains' => 0, 'ranking_seconds' => 0.0, 'elapsed_seconds' => 0.0, 'chain_depth_limit' => 0, 'explored_state_limit' => 0, 'fixed_blockers' => 0, 'no_eligible_candidate' => 0];
        $this->vacancyDiagnostics = [];
        $this->staticallyEligible = $this->conflictingShifts = [];
        $this->doctors = $doctors;
        foreach ($shifts as $shift) {
            foreach ($doctors as $doctor) {
                $history = $previousHistory->get($doctor->id);
                if ($this->eligibility->conflicts($doctor, $shift, $excludedDoctorIds->has($doctor->id), $dayOffRequests->get($doctor->id, collect()), collect(), $history?->most_recent_night_shift_at) === []) {
                    $this->staticallyEligible[$shift->id][$doctor->id] = true;
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
            $required = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
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
        $eligibleDoctors = $this->doctors->filter(fn (Doctor $doctor): bool => isset($this->staticallyEligible[$shift->id][$doctor->id]));
        $candidates = [];
        foreach ($this->ranker->ordered($eligibleDoctors, $shift, $role, $assignedByDoctor) as $ranking => $doctor) {
            $this->diagnostics['candidates']++;
            $blockers = [];
            foreach ($entriesByDoctor[$doctor->id] ?? [] as $key => $entry) {
                if ($this->shiftsConflict($shift, $entry['shift'])) {
                    $blockers[$key] = $entry;
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
        usort($candidates, fn (array $left, array $right): int => count($left['blockers']) <=> count($right['blockers']) ?: $left['ranking'] <=> $right['ranking']);

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
                uasort($blockers, fn (array $left, array $right): int => count($this->staticallyEligible[$left['shift']->id] ?? []) <=> count($this->staticallyEligible[$right['shift']->id] ?? []) ?: strcmp($this->key($left['shift'], $left['role'], $left['slot']), $this->key($right['shift'], $right['role'], $right['slot'])));
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

    /**
     * @param  array<int, array<string, array{shift: RosterShift, role: RosterAssignmentRole, slot: int, doctor_id: int, fixed: bool}>>  $entriesByDoctor
     * @return Collection<int, Collection<int, RosterShift>>
     */
    private function assignedByDoctor(array &$entriesByDoctor): Collection
    {
        $assigned = collect();
        foreach ($this->plan as $key => $entry) {
            $entriesByDoctor[$entry['doctor_id']][$key] = $entry;
            if (! $assigned->has($entry['doctor_id'])) {
                $assigned->put($entry['doctor_id'], collect());
            }
            $assigned->get($entry['doctor_id'])->push($entry['shift']);
        }

        return $assigned;
    }

    private function shiftsConflict(RosterShift $shift, RosterShift $otherShift): bool
    {
        if (! isset($this->conflictingShifts[$shift->id][$otherShift->id])) {
            $this->conflictingShifts[$shift->id][$otherShift->id] = $this->eligibility->shiftConflict($shift, $otherShift) !== null;
        }

        return $this->conflictingShifts[$shift->id][$otherShift->id];
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
