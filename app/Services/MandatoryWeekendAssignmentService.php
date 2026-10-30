<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\WeekendPairConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MandatoryWeekendAssignmentService
{
    public function __construct(
        private WeekendGroupRotationService $groups,
        private WeekendPairRotationService $pairs,
        private WeekendDayCapacityService $capacities,
        private DoctorAssignmentEligibilityService $eligibility,
        private DoctorMonthlyParticipationService $participation,
    ) {}

    /**
     * Resolve identities only through the Saturday plan and verify saved capacities.
     * Confirmation and availability are checked before these become reservations.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @return array<int, list<int>>
     */
    public function resolve(Roster $roster, Collection $shifts): array
    {
        $expected = [];
        foreach ($this->groups->weekendStartsForMonth($roster->year, $roster->month) as $saturday) {
            $date = $saturday->toDateString();
            if (! WeekendPairConfiguration::query()->whereDate('effective_saturday', '<=', $date)->exists()) {
                continue;
            }
            $plan = $this->pairs->forSaturday($date);
            if ($plan === null) {
                $this->fail("Weekend {$date}: the scheduled group has no effective pair configuration. Review Monthly Weekend Pairs.");
            }
            if ($saturday->year !== $roster->year || $saturday->month !== $roster->month) {
                $this->assertHistoricalSaturday($saturday, $plan);
            }
            foreach (['saturday' => $saturday, 'sunday' => $saturday->addDay()] as $day => $shiftDate) {
                if ($shiftDate->year !== $roster->year || $shiftDate->month !== $roster->month) {
                    continue;
                }
                foreach (['day', 'night'] as $duty) {
                    $code = 'weekend_'.$duty;
                    $matches = $shifts->filter(fn (RosterShift $shift): bool => $shift->shift_date->isSameDay($shiftDate) && $shift->shiftType->code === $code);
                    if ($matches->count() !== 1) {
                        $this->fail("{$shiftDate->toDateString()} {$code}: exactly one saved roster shift is required for mandatory pairs.");
                    }
                    $shift = $matches->first();
                    $ids = $plan['shifts'][$day.'_'.$duty];
                    if ($shift->savedCapacity()->main_count !== count($ids)) {
                        $this->fail("{$shiftDate->toDateString()} {$shift->shiftType->name}: saved Main capacity conflicts with the confirmed pair plan (expected ".count($ids).'). Saved snapshots cannot be changed by generation.');
                    }
                    $expected[$shift->id] = $ids;
                }
            }
        }

        return $expected;
    }

    /**
     * Diagnose required doctors before the generator's broader population guard.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @param  array<int, list<int>>  $expected
     */
    public function assertPopulation(Roster $roster, Collection $shifts, array $expected): void
    {
        if ($expected === []) {
            return;
        }
        $ids = array_unique(array_merge(...array_values($expected)));
        $doctors = Doctor::query()->whereIn('id', $ids)->get()->keyBy('id');
        $participating = $this->participation->forRoster($roster, $ids);
        foreach ($shifts as $shift) {
            foreach ($expected[$shift->id] ?? [] as $id) {
                $doctor = $doctors->get($id);
                if ($doctor === null || ! $doctor->is_active || ! $participating->get($id, false)) {
                    $label = $doctor === null ? "Doctor ID {$id}" : "{$doctor->name} ({$doctor->short_code})";
                    $this->fail("{$label}: {$shift->shift_date->toDateString()} {$shift->shiftType->name} mandatory Main requires an active, participating doctor. Restore the saved monthly population before generating; no substitute was selected.");
                }
            }
        }
    }

    /**
     * Validate before modifying the in-memory assignment relations. New assignments
     * remain unsaved, so the caller persists them with the ordinary plan atomically.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @param  array<int, list<int>>  $expected
     * @param  Collection<int, int>  $excluded
     * @param  Collection<int|string, Collection<int, DoctorRequest>>  $dayOffRequests
     * @param  Collection<int, DoctorMonthlyWorkload>  $history
     * @param  array<int, array<int, true>>  $restrictions
     * @param  Collection<int, bool>  $participating
     * @param  Collection<int, true>  $previousNight
     * @return list<array{roster_shift_id: int, doctor_id: int, role: RosterAssignmentRole, slot_number: int}>
     */
    public function reserve(Roster $roster, Collection $shifts, array $expected, Collection $excluded, Collection $dayOffRequests, Collection $history, array $restrictions, Collection $participating, Collection $previousNight): array
    {
        if ($expected === []) {
            return [];
        }
        $doctors = Doctor::query()->whereIn('id', array_unique(array_merge(...array_values($expected))))->get()->keyBy('id');
        $assigned = collect();
        foreach ($shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                if (! $assigned->has($assignment->doctor_id)) {
                    $assigned->put($assignment->doctor_id, collect());
                }
                $assigned->get($assignment->doctor_id)->push(['shift' => $shift, 'role' => $assignment->role]);
            }
        }
        $new = [];
        foreach ($shifts as $shift) {
            if (! isset($expected[$shift->id])) {
                continue;
            }
            $ids = $expected[$shift->id];
            $existing = $shift->assignments->where('role', RosterAssignmentRole::Main);
            if ($existing->pluck('doctor_id')->unique()->count() !== $existing->count()
                || $existing->pluck('slot_number')->unique()->count() !== $existing->count()
                || $existing->contains(fn (RosterAssignment $assignment): bool => ! in_array($assignment->doctor_id, $ids, true))) {
                $this->fail("{$shift->shift_date->toDateString()} {$shift->shiftType->name}: existing Draft Main assignments do not match the mandatory pair plan. Correct the assignments or explicitly regenerate the Draft.");
            }
            $freeSlots = collect(range(1, count($ids)))->diff($existing->pluck('slot_number'))->values();
            foreach ($ids as $doctorId) {
                $doctor = $doctors->get($doctorId);
                $label = $doctor === null ? "Doctor ID {$doctorId}" : "{$doctor->name} ({$doctor->short_code})";
                $otherAssignments = $assigned->get($doctorId, collect())->reject(fn (array $entry): bool => $entry['shift']->id === $shift->id && $entry['role'] === RosterAssignmentRole::Main);
                $reasons = $doctor === null ? ['missing doctor'] : $this->eligibility->roleAwareConflicts(
                    $doctor, $shift, RosterAssignmentRole::Main,
                    $excluded->has($doctorId), $participating->get($doctorId, false),
                    $dayOffRequests->get($doctorId, collect()), $otherAssignments,
                    $history->get($doctorId)?->most_recent_night_shift_at, true,
                    isset($restrictions[$doctorId][$shift->shift_type_id]), $previousNight,
                );
                if ($reasons !== []) {
                    $reason = str_replace('_', ' ', implode(', ', $reasons));
                    $this->fail("{$label}: {$shift->shift_date->toDateString()} {$shift->shiftType->name} mandatory Main conflicts with {$reason}. Resolve availability or the conflicting Draft assignment; no substitute was selected.");
                }
                if ($existing->contains('doctor_id', $doctorId)) {
                    continue;
                }
                $new[] = ['roster_shift_id' => $shift->id, 'doctor_id' => $doctorId, 'role' => RosterAssignmentRole::Main, 'slot_number' => $freeSlots->shift()];
                if (! $assigned->has($doctorId)) {
                    $assigned->put($doctorId, collect());
                }
                $assigned->get($doctorId)->push(['shift' => $shift, 'role' => RosterAssignmentRole::Main]);
            }
        }

        $this->capacities->forMonth($roster->year, $roster->month, 0);
        $byId = $shifts->keyBy('id');
        foreach ($new as $attributes) {
            $byId->get($attributes['roster_shift_id'])->assignments->push(new RosterAssignment($attributes));
        }

        return $new;
    }

    /** @param array<string, mixed> $plan */
    private function assertHistoricalSaturday(CarbonImmutable $saturday, array $plan): void
    {
        $roster = Roster::query()->where('year', $saturday->year)->where('month', $saturday->month)->first();
        if ($roster?->status !== RosterStatus::Final) {
            $this->fail("Weekend {$saturday->toDateString()}: finalize the historical Saturday roster with its confirmed pairs before generating Sunday.");
        }
        $shifts = $roster->shifts()->with(['shiftType', 'assignments', 'capacitySnapshot'])->whereDate('shift_date', $saturday->toDateString())->get();
        $participating = $this->participation->forRoster($roster, array_merge($plan['shifts']['saturday_day'], $plan['shifts']['saturday_night']));
        foreach (['day', 'night'] as $duty) {
            $matches = $shifts->filter(fn (RosterShift $shift): bool => $shift->shiftType->code === 'weekend_'.$duty);
            $shift = $matches->first();
            $ids = collect($plan['shifts']['saturday_'.$duty])->sort()->values()->all();
            $main = $shift?->assignments->where('role', RosterAssignmentRole::Main) ?? collect();
            try {
                $mainCount = $shift?->savedCapacity()->main_count;
            } catch (\LogicException $exception) {
                $this->fail("Weekend {$saturday->toDateString()} {$duty}: ".$exception->getMessage().' Review the historical Final Saturday capacity snapshot.');
            }
            if ($matches->count() !== 1 || $mainCount !== count($ids)
                || $main->pluck('doctor_id')->sort()->values()->all() !== $ids
                || $main->pluck('slot_number')->sort()->values()->all() !== range(1, count($ids))
                || collect($ids)->contains(fn (int $id): bool => ! $participating->get($id, false))) {
                $this->fail("Weekend {$saturday->toDateString()} {$duty}: historical Final Saturday assignments, participation, or saved capacity are incompatible with its confirmed pair plan. Review the historical boundary; generation cannot rewrite Final assignments.");
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['roster' => $message]);
    }
}
