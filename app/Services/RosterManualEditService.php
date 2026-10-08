<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterManualEditService
{
    public const UNDO_KEY = 'roster_manual_undo';

    public function __construct(private RosterDraftContext $context) {}

    /** @param array<string, int|string|bool|null> $input
     * @return array{status: string, warnings: list<string>}
     */
    public function edit(Roster $roster, User $admin, string $operation, array $input, bool $confirmed): array
    {
        return DB::transaction(function () use ($roster, $admin, $operation, $input, $confirmed): array {
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);
            $this->requireDraft($roster);
            $this->context->load($roster);
            $this->context->assertParticipationPopulationMatches();
            $first = $this->slot($input, '');
            $affected = [$first];
            $this->assertExpected($first, $input, '');
            $state = $this->context->state();
            $before = $this->snapshot($affected);
            $next = $state;
            $firstKey = $this->key($first);
            $incoming = [];

            if ($operation === 'replace') {
                $doctorId = (int) $input['doctor_id'];
                if (! $this->context->doctors->has($doctorId)) {
                    throw ValidationException::withMessages(['edit' => 'The selected doctor does not exist.']);
                }
                if (($state[$firstKey] ?? null) === $doctorId) {
                    throw ValidationException::withMessages(['edit' => 'This doctor already occupies the selected slot.']);
                }
                $source = $this->findDoctorOnShift($first['shift']->id, $doctorId, $state);
                if ($source !== null) {
                    $isOptionalPromotion = $first['role'] === RosterAssignmentRole::Main
                        && $source['role'] === RosterAssignmentRole::Optional;
                    if (! isset($state[$firstKey]) && ! $isOptionalPromotion) {
                        throw ValidationException::withMessages(['edit' => 'Already assigned to this shift.']);
                    }
                    if ($source['role'] === $first['role']) {
                        throw ValidationException::withMessages(['edit' => 'This doctor is already assigned to another slot of the same role on this shift.']);
                    }
                    $sourceAssignment = $this->context->assignment($source['shift']->id, $source['role'], $source['slot']);
                    if (($input['expected_source_assignment_id'] ?? null) !== $sourceAssignment?->id) {
                        $this->stale();
                    }
                    $affected[] = $source;
                    $before = $this->snapshot($affected);
                    $sourceKey = $this->key($source);
                    unset($next[$sourceKey]);
                    if (isset($state[$firstKey])) {
                        $next[$sourceKey] = $state[$firstKey];
                        $incoming[] = [$source, $state[$firstKey]];
                    }
                } elseif (($input['expected_source_assignment_id'] ?? null) !== null) {
                    $this->stale();
                }
                $next[$firstKey] = $doctorId;
                $incoming[] = [$first, $doctorId];
            } elseif ($operation === 'swap') {
                $second = $this->slot($input, 'target_');
                $this->assertExpected($second, $input, 'target_');
                $secondKey = $this->key($second);
                if ($firstKey === $secondKey || ! isset($state[$firstKey], $state[$secondKey])) {
                    throw ValidationException::withMessages(['edit' => 'Choose two different occupied slots to swap.']);
                }
                $affected[] = $second;
                $before = $this->snapshot($affected);
                $next[$firstKey] = $state[$secondKey];
                $next[$secondKey] = $state[$firstKey];
                $incoming[] = [$first, $state[$secondKey]];
                $incoming[] = [$second, $state[$firstKey]];
            } elseif ($operation === 'clear') {
                if (! isset($state[$firstKey])) {
                    throw ValidationException::withMessages(['edit' => 'This slot is already empty.']);
                }
                unset($next[$firstKey]);
            } else {
                abort(422);
            }

            $base = $state;
            foreach ($affected as $slot) {
                unset($base[$this->key($slot)]);
            }
            foreach ($incoming as [$slot, $doctorId]) {
                $testing = $next;
                unset($testing[$this->key($slot)]);
                $reasons = $this->context->hardReasons($doctorId, $slot['shift'], $testing);
                if ($reasons !== []) {
                    throw ValidationException::withMessages(['edit' => implode(' ', $reasons)]);
                }
            }

            $warnings = $this->softWarnings($state, $next, $base, $incoming);
            if ($warnings !== [] && ! $confirmed) {
                return ['status' => 'confirmation_required', 'warnings' => $warnings];
            }

            $this->write($affected, $next);
            $roster->update(['updated_by' => $admin->id]);
            session()->put(self::UNDO_KEY, [
                'roster_id' => $roster->id,
                'before' => $before,
                'after' => $this->snapshot($affected),
                'generated_at' => $roster->last_generated_at?->toDateTimeString(),
                'plan_signature' => $this->planSignature(),
            ]);

            return ['status' => 'saved', 'warnings' => $warnings];
        });
    }

    public function undo(Roster $roster, User $admin): void
    {
        DB::transaction(function () use ($roster, $admin): void {
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);
            $this->requireDraft($roster);
            $record = session()->get(self::UNDO_KEY);
            if (! is_array($record) || ($record['roster_id'] ?? null) !== $roster->id
                || ($record['generated_at'] ?? null) !== $roster->last_generated_at?->toDateTimeString()) {
                throw ValidationException::withMessages(['edit' => 'Undo is no longer available for this roster.']);
            }
            $this->context->load($roster);
            $this->context->assertParticipationPopulationMatches();
            if (($record['plan_signature'] ?? null) !== $this->planSignature()) {
                $this->stale();
            }
            $after = $record['after'];
            $before = $record['before'];
            foreach ($after as $key => $expected) {
                $slot = $this->parseKey($key);
                $current = $this->context->assignment($slot['shift']->id, $slot['role'], $slot['slot']);
                if ($current?->id !== ($expected['id'] ?? null) || $current?->doctor_id !== ($expected['doctor_id'] ?? null)) {
                    $this->stale();
                }
            }
            $slots = [];
            foreach (array_keys($after) as $key) {
                $slots[] = $this->parseKey((string) $key);
            }
            $state = $this->context->state();
            foreach ($before as $key => $previous) {
                unset($state[$key]);
                if ($previous['doctor_id'] !== null) {
                    $state[$key] = $previous['doctor_id'];
                }
            }
            foreach ($before as $key => $previous) {
                if ($previous['doctor_id'] === null) {
                    continue;
                }
                $slot = $this->parseKey((string) $key);
                $testing = $state;
                unset($testing[$key]);
                $reasons = $this->context->hardReasons($previous['doctor_id'], $slot['shift'], $testing);
                if ($reasons !== []) {
                    throw ValidationException::withMessages(['edit' => 'Undo would restore an invalid assignment. '.implode(' ', $reasons)]);
                }
            }
            $this->write($slots, $state);
            $roster->update(['updated_by' => $admin->id]);
            session()->forget(self::UNDO_KEY);
        });
    }

    /** @param array<string, int|string|bool|null> $input
     * @return array{shift: RosterShift, role: RosterAssignmentRole, slot: int}
     */
    private function slot(array $input, string $prefix): array
    {
        $role = RosterAssignmentRole::from((string) $input[$prefix.'role']);
        $slot = (int) $input[$prefix.'slot_number'];

        return ['shift' => $this->context->shift((int) $input[$prefix.'shift_id'], $role, $slot), 'role' => $role, 'slot' => $slot];
    }

    /** @param array{shift: RosterShift, role: RosterAssignmentRole, slot: int} $slot */
    private function key(array $slot): string
    {
        return $this->context->key($slot['shift']->id, $slot['role'], $slot['slot']);
    }

    /** @param array{shift: RosterShift, role: RosterAssignmentRole, slot: int} $slot
     * @param  array<string, int|string|bool|null>  $input
     */
    private function assertExpected(array $slot, array $input, string $prefix): void
    {
        $current = $this->context->assignment($slot['shift']->id, $slot['role'], $slot['slot']);
        if ($current?->id !== ($input[$prefix.'expected_assignment_id'] ?? null)
            || $current?->doctor_id !== ($input[$prefix.'expected_doctor_id'] ?? null)) {
            $this->stale();
        }
    }

    /** @param array<string, int> $state
     * @return array{shift: RosterShift, role: RosterAssignmentRole, slot: int}|null
     */
    private function findDoctorOnShift(int $shiftId, int $doctorId, array $state): ?array
    {
        foreach ($state as $key => $assignedDoctorId) {
            if ($assignedDoctorId === $doctorId && str_starts_with($key, "$shiftId:")) {
                return $this->parseKey($key);
            }
        }

        return null;
    }

    /** @return array{shift: RosterShift, role: RosterAssignmentRole, slot: int} */
    private function parseKey(string $key): array
    {
        [$shiftId, $role, $slot] = explode(':', $key);
        $role = RosterAssignmentRole::from($role);

        return ['shift' => $this->context->shift((int) $shiftId, $role, (int) $slot), 'role' => $role, 'slot' => (int) $slot];
    }

    /** @param list<array{shift: RosterShift, role: RosterAssignmentRole, slot: int}> $slots
     * @return array<string, array{id: int|null, doctor_id: int|null}>
     */
    private function snapshot(array $slots): array
    {
        $snapshot = [];
        foreach ($slots as $slot) {
            $assignment = $this->context->assignment($slot['shift']->id, $slot['role'], $slot['slot']);
            $snapshot[$this->key($slot)] = ['id' => $assignment?->id, 'doctor_id' => $assignment?->doctor_id];
        }

        return $snapshot;
    }

    /** @param list<array{shift: RosterShift, role: RosterAssignmentRole, slot: int}> $slots
     * @param  array<string, int>  $state
     */
    private function write(array $slots, array $state): void
    {
        foreach ($slots as $slot) {
            $assignment = $this->context->assignment($slot['shift']->id, $slot['role'], $slot['slot']);
            $assignment?->delete();
        }
        foreach ($slots as $slot) {
            $doctorId = $state[$this->key($slot)] ?? null;
            if ($doctorId !== null) {
                $assignment = RosterAssignment::create([
                    'roster_shift_id' => $slot['shift']->id,
                    'role' => $slot['role'],
                    'slot_number' => $slot['slot'],
                    'doctor_id' => $doctorId,
                ]);
                $slot['shift']->assignments->push($assignment);
            }
        }
        foreach ($slots as $slot) {
            $slot['shift']->setRelation('assignments', $slot['shift']->assignments->filter(fn (RosterAssignment $assignment): bool => $assignment->exists)->values());
        }
    }

    /** @param array<string, int> $before
     * @param  array<string, int>  $after
     * @param  array<string, int>  $base
     * @param  list<array{0: array{shift: RosterShift, role: RosterAssignmentRole, slot: int}, 1: int}>  $incoming
     * @return list<string>
     */
    private function softWarnings(array $before, array $after, array $base, array $incoming): array
    {
        $warnings = [];
        foreach ($this->context->preferred as $request) {
            if ($this->context->preferenceFulfilled($request, $before) && ! $this->context->preferenceFulfilled($request, $after)) {
                $warnings[] = "This change leaves {$request->doctor->name}'s Preferred Work request for {$request->request_date->format('M j')} {$request->shiftType?->name} unfulfilled.";
            }
        }
        foreach ($incoming as [$slot, $doctorId]) {
            $shift = $slot['shift'];
            foreach ($this->context->blockedPreferredShifts($doctorId, $shift, $slot['role'], $base) as $preferredShift) {
                $request = $this->context->preferred->first(fn (DoctorRequest $preferred): bool => $preferred->doctor_id === $doctorId
                    && $preferred->request_date->isSameDay($preferredShift->shift_date)
                    && $preferred->shift_type_id === $preferredShift->shift_type_id);
                if ($request === null || $this->context->preferenceFulfilled($request, $before)) {
                    continue;
                }
                $doctor = $this->context->doctors->get($doctorId);
                $warnings[] = "Assigning $doctor->name to {$shift->shift_date->format('M j')} {$shift->shiftType->name} {$slot['role']->value} will block {$doctor->name}'s Preferred Work Main request for {$preferredShift->shift_date->format('M j')} {$preferredShift->shiftType->name}.";
            }
            $best = null;
            foreach ($this->context->doctors as $candidate) {
                if ($this->context->hardReasons($candidate->id, $shift, $base) !== []) {
                    continue;
                }
                $dimensions = $this->context->dimensions($candidate->id, $shift, $slot['role'], $base);
                if ($best === null || $dimensions < $best) {
                    $best = $dimensions;
                }
            }
            if ($best !== null && $this->context->dimensions($doctorId, $shift, $slot['role'], $base) > $best) {
                $warnings[] = "{$this->context->doctors->get($doctorId)->name} ranks below another available doctor for {$shift->shift_date->format('M j')} {$shift->shiftType->name} {$slot['role']->value}. Continue?";
            }
        }

        return array_values(array_unique($warnings));
    }

    private function requireDraft(Roster $roster): void
    {
        if ($roster->status !== RosterStatus::Draft) {
            throw ValidationException::withMessages(['edit' => 'Only a Draft roster can be edited.']);
        }
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['edit' => 'This roster slot changed since the page was loaded. Refresh and try again.']);
    }

    private function planSignature(): string
    {
        $assignments = RosterAssignment::query()
            ->whereIn('roster_shift_id', $this->context->shifts->keys())
            ->orderBy('id')
            ->get(['id', 'roster_shift_id', 'doctor_id', 'role', 'slot_number']);

        return hash('sha256', $assignments->toJson());
    }
}
