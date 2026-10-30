<?php

namespace App\Services;

use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\MonthlyShiftCapacity;
use App\Models\MonthlyWeekendPairConfirmation;
use App\Models\Roster;
use App\Models\ShiftType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RecreateDraftRoster
{
    public function __construct(
        private WeekendPairCoordination $coordination,
        private WeekendDayCapacityService $weekendCapacities,
        private MonthlyShiftCapacityService $capacities,
        private DoctorMonthlyParticipationService $participation,
        private RosterHistoryReadinessService $history,
        private RosterLifecycleService $lifecycle,
        private RosterPlanningPeriodService $periods,
        private RosterAssignmentGenerator $generator,
        private MonthlyWeekendPairService $pairs,
        private RosterDraftValidationService $validation,
    ) {}

    public function handle(Roster $roster, User $admin, int $legacyMainCount, bool $confirmed): Roster
    {
        return DB::transaction(function () use ($roster, $admin, $legacyMainCount, $confirmed): Roster {
            $this->coordination->lock();
            $types = ShiftType::query()->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);

            if (! $confirmed) {
                throw ValidationException::withMessages(['confirmation' => 'Confirm that all Draft assignments, including manual edits, will be lost.']);
            }
            if ($legacyMainCount < 0 || $legacyMainCount > 255) {
                throw ValidationException::withMessages(['legacy_main_count' => 'Capacity must be an integer from 0 through 255.']);
            }
            $this->assertRecreatable($roster);
            $weekends = $this->weekendCapacities->forMonth($roster->year, $roster->month, $legacyMainCount);
            $dayType = $types->get('weekend_day');
            if ($dayType === null || ! $dayType->is_active) {
                throw ValidationException::withMessages(['roster' => 'Weekend Day must be active before recreating the Draft.']);
            }
            $configured = $this->capacities->resolve($roster->year, $roster->month, $dayType);
            $shifts = $roster->shifts()->with(['shiftType', 'capacitySnapshot'])->orderBy('id')->get();
            $replacement = [];
            foreach ($shifts as $shift) {
                $snapshot = $shift->savedCapacity();
                $main = $snapshot->main_count;
                if ($shift->shiftType->code === 'weekend_day') {
                    $saturday = $shift->shift_date->isSunday() ? $shift->shift_date->copy()->subDay() : $shift->shift_date;
                    $weekend = $weekends[$saturday->toDateString()];
                    if ($weekend['source'] === 'confirmed_pairs' && $main !== $weekend['main_count']) {
                        throw ValidationException::withMessages(['roster' => 'Saved pair capacity conflicts with the confirmed mode. Resolve the pair configuration before recreating.']);
                    }
                    $main = $weekend['main_count'];
                }
                $replacement[] = [
                    'shift_type_id' => $shift->shift_type_id, 'shift_date' => $shift->shift_date->toDateString(),
                    'main_count' => $main, 'optional_count' => $snapshot->optional_count,
                ];
            }

            MonthlyShiftCapacity::query()->updateOrCreate(
                ['year' => $roster->year, 'month' => $roster->month, 'shift_type_id' => $dayType->id],
                ['main_count' => $legacyMainCount, 'optional_count' => $configured['optional_count']],
            );
            $discardedAssignments = $roster->shifts()->withCount('assignments')->get()->sum('assignments_count');
            $oldShiftIds = $shifts->modelKeys();
            $this->replaceShifts($roster, $admin, $replacement);

            DB::afterCommit(function () use ($roster, $admin, $configured, $legacyMainCount, $discardedAssignments, $oldShiftIds): void {
                Log::info('Draft roster recreated with updated legacy Weekend Day capacity.', [
                    'roster_id' => $roster->id, 'year' => $roster->year, 'month' => $roster->month,
                    'admin_id' => $admin->id, 'previous_legacy_main_count' => $configured['main_count'],
                    'legacy_main_count' => $legacyMainCount, 'discarded_assignments' => $discardedAssignments,
                    'discarded_shift_ids' => $oldShiftIds,
                ]);
                $this->clearUndo($roster);
            });

            return $roster->refresh();
        });
    }

    /** @return array{revision: string, current_optional_count: int, exclusions: list<array{id: int, name: string, short_code: string}>}|null */
    public function monthlySetupReview(Roster $roster): ?array
    {
        try {
            $state = $this->monthlySetupState($roster);
        } catch (ValidationException) {
            return null;
        }

        return [
            'revision' => $state['revision'],
            'current_optional_count' => $state['capacity']['optional_count'],
            'exclusions' => $state['candidates'],
        ];
    }

    public function handleUpdatedSetup(Roster $roster, User $admin, int $doctorId, int $optionalCount, bool $confirmed, string $revision): Roster
    {
        return DB::transaction(function () use ($roster, $admin, $doctorId, $optionalCount, $confirmed, $revision): Roster {
            $this->coordination->lock();
            ShiftType::query()->orderBy('id')->lockForUpdate()->get();
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);
            Doctor::query()->orderBy('id')->lockForUpdate()->get(['id']);

            if (! $confirmed) {
                throw ValidationException::withMessages(['confirmation' => 'Confirm that all Draft assignments, including manual edits, will be lost.']);
            }
            if ($optionalCount < 0 || $optionalCount > 255) {
                throw ValidationException::withMessages(['optional_count' => 'Capacity must be an integer from 0 through 255.']);
            }
            $savedParticipation = DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)
                ->where('year', $roster->year)->where('month', $roster->month)->where('doctor_id', $doctorId)->first();
            if ($savedParticipation !== null && ! $savedParticipation->is_participating) {
                throw ValidationException::withMessages(['doctor_id' => 'This doctor was not participating in the saved roster snapshot. This action cannot change participation history.']);
            }
            $state = $this->monthlySetupState($roster);
            if (! hash_equals($state['revision'], $revision)) {
                throw ValidationException::withMessages(['revision' => 'The Draft or monthly setup changed. Reload the roster and review the changes before recreating.']);
            }
            if (! in_array($doctorId, array_column($state['candidates'], 'id'), true)) {
                throw ValidationException::withMessages(['doctor_id' => 'Choose an active, saved participating doctor with an existing whole-month exclusion. Participation history cannot be rewritten by this action.']);
            }
            if ($optionalCount === $state['capacity']['optional_count']) {
                throw ValidationException::withMessages(['optional_count' => 'Choose a different Weekday Day Optional capacity.']);
            }

            DoctorMonthlyExclusion::query()->where('year', $roster->year)->where('month', $roster->month)
                ->where('doctor_id', $doctorId)->sole()->delete();
            $confirmationId = $this->reconcileMonthlyConfirmation($roster, $admin, $state['pairs']);
            MonthlyShiftCapacity::query()->updateOrCreate(
                ['year' => $roster->year, 'month' => $roster->month, 'shift_type_id' => $state['day_type_id']],
                ['main_count' => $state['capacity']['main_count'], 'optional_count' => $optionalCount],
            );

            $replacement = $state['replacement'];
            foreach ($replacement as &$values) {
                if ($values['shift_type_id'] === $state['day_type_id']) {
                    $values['optional_count'] = $optionalCount;
                }
            }
            unset($values);
            $this->replaceShifts($roster, $admin, $replacement);
            $errors = collect($this->validation->validate($roster->fresh()))->where('severity', 'Error');
            if ($errors->isNotEmpty()) {
                throw ValidationException::withMessages(['roster' => $errors->pluck('message')->all()]);
            }

            DB::afterCommit(function () use ($roster, $admin, $doctorId, $optionalCount, $confirmationId, $state): void {
                Log::info('Draft roster recreated with updated monthly setup.', [
                    'roster_id' => $roster->id, 'year' => $roster->year, 'month' => $roster->month,
                    'admin_id' => $admin->id, 'removed_exclusion_doctor_id' => $doctorId,
                    'previous_optional_count' => $state['capacity']['optional_count'], 'optional_count' => $optionalCount,
                    'previous_confirmation_id' => $state['pairs']['confirmation']['id'], 'confirmation_id' => $confirmationId,
                    'configuration_ids' => $state['pairs']['confirmation']['configuration_ids'],
                    'discarded_assignments' => $state['discarded_assignments'],
                ]);
                $this->clearUndo($roster);
            });

            return $roster->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function monthlySetupState(Roster $roster): array
    {
        $this->assertRecreatable($roster);
        $this->participation->assertRosterPopulationMatchesActiveDoctors($roster, Doctor::query()->where('is_active', true)->get());
        $pairs = $this->pairs->load($roster->year, $roster->month);
        if ($pairs['status'] !== 'confirmed') {
            throw ValidationException::withMessages(['configuration' => 'The existing monthly weekend pairs must be confirmed and current before recreating. Reload and review the monthly setup.']);
        }
        $this->assertSixDoctorRotations($pairs);
        $types = ShiftType::query()->orderBy('id')->get()->keyBy('code');
        $dayType = $types->get('weekday_day');
        if ($dayType === null || ! $dayType->is_active) {
            throw ValidationException::withMessages(['roster' => 'Weekday Day must be active before recreating.']);
        }
        $capacity = $this->capacities->resolve($roster->year, $roster->month, $dayType);
        $legacy = $this->capacities->resolve($roster->year, $roster->month, 'weekend_day');
        $weekends = $this->weekendCapacities->forMonth($roster->year, $roster->month, $legacy['main_count']);
        $configuredCapacities = [];
        foreach ($types as $code => $type) {
            $configuredCapacities[$code] = $this->capacities->resolve($roster->year, $roster->month, $type);
        }
        $shifts = $roster->shifts()->with(['shiftType', 'capacitySnapshot', 'assignments' => fn ($query) => $query->orderBy('id')])->orderBy('id')->get();
        $expected = [];
        $date = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        while ($date->month === $roster->month) {
            foreach ($date->isWeekend() ? ['weekend_day', 'weekend_night'] : ['weekday_day', 'weekday_evening', 'weekday_night'] as $code) {
                $expected[] = $date->toDateString().'|'.$code;
            }
            $date = $date->addDay();
        }
        $actual = [];
        $replacement = [];
        foreach ($shifts as $shift) {
            try {
                $snapshot = $shift->savedCapacity();
            } catch (\LogicException $exception) {
                throw ValidationException::withMessages(['roster' => $exception->getMessage()]);
            }
            $code = $shift->shiftType->code;
            $actual[] = $shift->shift_date->toDateString().'|'.$code;
            if (! $shift->shiftType->is_active
                || $snapshot->optional_count !== $configuredCapacities[$code]['optional_count']
                || ($code !== 'weekend_day' && $snapshot->main_count !== $configuredCapacities[$code]['main_count'])
                || ($code === 'weekend_night' && $snapshot->main_count !== 2)) {
                throw ValidationException::withMessages(['roster' => 'Saved shift capacities are inconsistent with this controlled recreation. Review the existing snapshots.']);
            }
            if ($code === 'weekend_day') {
                $saturday = $shift->shift_date->isSunday() ? $shift->shift_date->copy()->subDay() : $shift->shift_date;
                if ($snapshot->main_count !== ($weekends[$saturday->toDateString()]['main_count'] ?? null)) {
                    throw ValidationException::withMessages(['roster' => 'Saved Weekend Day capacity conflicts with its confirmed or legacy mode. Use the legacy correction action first if required.']);
                }
            }
            $replacement[] = [
                'shift_type_id' => $shift->shift_type_id, 'shift_date' => $shift->shift_date->toDateString(),
                'main_count' => $snapshot->main_count, 'optional_count' => $snapshot->optional_count,
            ];
        }
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['roster' => 'The saved monthly shift structure is incomplete or inconsistent.']);
        }
        $participation = DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->orderBy('id')->get();
        $exclusions = DoctorMonthlyExclusion::query()->with('doctor')->where('year', $roster->year)->where('month', $roster->month)->orderBy('id')->get();
        $candidates = $exclusions->filter(fn (DoctorMonthlyExclusion $exclusion): bool => $exclusion->doctor->is_active
            && $participation->where('doctor_id', $exclusion->doctor_id)->where('is_participating', true)->isNotEmpty())
            ->map(fn (DoctorMonthlyExclusion $exclusion): array => [
                'id' => $exclusion->doctor_id, 'name' => $exclusion->doctor->name, 'short_code' => $exclusion->doctor->short_code,
            ])->values()->all();
        $revision = hash_hmac('sha256', json_encode([
            $roster->getAttributes(), $shifts->toArray(), $participation->toArray(), $exclusions->toArray(),
            $types->toArray(), $capacity, $legacy, $pairs['revision'],
            MonthlyShiftCapacity::query()->where('year', $roster->year)->where('month', $roster->month)->orderBy('id')->get()->toArray(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return compact('revision', 'capacity', 'candidates', 'pairs', 'replacement') + [
            'day_type_id' => $dayType->id, 'discarded_assignments' => $shifts->sum(fn ($shift): int => $shift->assignments->count()),
        ];
    }

    /**
     * Only called after the locked Draft and reviewed state have been validated,
     * and its one selected exclusion has been removed in the enclosing transaction.
     * No effective-dated configuration is created or changed here.
     *
     * @param  array<string, mixed>  $previous
     */
    private function reconcileMonthlyConfirmation(Roster $roster, User $admin, array $previous): int
    {
        $current = $this->pairs->load($roster->year, $roster->month);
        $this->assertSixDoctorRotations($current);
        if ($current['confirmation'] !== $previous['confirmation'] || $current['boundary'] !== $previous['boundary']) {
            throw ValidationException::withMessages(['configuration' => 'The confirmed weekend boundary changed. Reload before recreating.']);
        }
        foreach (['A', 'B'] as $group) {
            if ($current['groups'][$group]['configuration'] !== $previous['groups'][$group]['configuration']) {
                throw ValidationException::withMessages(['configuration' => 'The selected weekend rotation changed. Reload before recreating.']);
            }
        }
        if ($current['population_fingerprint'] === $previous['population_fingerprint']) {
            return $previous['confirmation']['id'];
        }
        $confirmation = MonthlyWeekendPairConfirmation::query()->create([
            'year' => $roster->year, 'month' => $roster->month,
            'supersedes_confirmation_id' => $previous['confirmation']['id'],
            'group_a_configuration_id' => $previous['confirmation']['configuration_ids']['A'],
            'group_b_configuration_id' => $previous['confirmation']['configuration_ids']['B'],
            'boundary_configuration_id' => $previous['confirmation']['boundary_configuration_id'],
            'population_fingerprint' => $current['population_fingerprint'], 'confirmed_by' => $admin->id,
        ]);
        $legacy = $this->capacities->resolve($roster->year, $roster->month, 'weekend_day');
        $this->weekendCapacities->forMonth($roster->year, $roster->month, $legacy['main_count']);

        return $confirmation->id;
    }

    /** @param array<string, mixed> $state */
    private function assertSixDoctorRotations(array $state): void
    {
        if ($state['issues'] !== [] || $state['population_fingerprint'] === null || $state['confirmation'] === null) {
            throw ValidationException::withMessages(['configuration' => 'The existing weekend rotations cannot safely be preserved. '.implode(' ', $state['issues'])]);
        }
        foreach (['A', 'B'] as $group) {
            $configuration = $state['groups'][$group]['configuration'];
            $selected = $configuration['doctor_ids'] ?? [];
            $eligible = array_column($state['groups'][$group]['doctors'], 'id');
            if (($configuration['mode'] ?? null) !== 6 || count($selected) !== 6 || count(array_unique($selected)) !== 6
                || array_diff($selected, $eligible) !== []
                || Doctor::query()->whereIn('id', $selected)->where('is_active', true)->count() !== 6
                || $state['confirmation']['configuration_ids'][$group] !== $configuration['id']) {
                throw ValidationException::withMessages(['configuration' => "Group {$group} must retain its six distinct active, eligible confirmed rotation doctors. No replacement was selected."]);
            }
        }
    }

    private function assertRecreatable(Roster $roster): void
    {
        if ($roster->status !== RosterStatus::Draft || $roster->finalized_at !== null) {
            throw ValidationException::withMessages(['roster' => 'Only a Draft roster that has never been Final can be recreated.']);
        }
        if (! $this->periods->isPlanningPeriod($roster->year, $roster->month)) {
            throw ValidationException::withMessages(['roster' => 'Only the current planning month can be recreated.']);
        }
        if (! $this->lifecycle->canReopen($roster) || $roster->monthlyWorkloads()->exists()
            || Roster::query()->where(function ($query) use ($roster): void {
                $query->where('year', '>', $roster->year)
                    ->orWhere(fn ($query) => $query->where('year', $roster->year)->where('month', '>', $roster->month));
            })->exists()) {
            throw ValidationException::withMessages(['roster' => 'This Draft has actual-work history or a later roster and cannot be recreated.']);
        }
        $this->participation->assertRosterSnapshotIntegrity($roster);
        $readiness = $this->history->forMonth($roster->year, $roster->month);
        if (! $readiness['ready']) {
            throw ValidationException::withMessages(['roster' => $readiness['message'] ?? 'Previous-month history is not ready.']);
        }
    }

    /** @param list<array{shift_type_id: int, shift_date: string, main_count: int, optional_count: int}> $replacement */
    private function replaceShifts(Roster $roster, User $admin, array $replacement): void
    {
        $roster->shifts()->delete();
        foreach ($replacement as $values) {
            $shift = $roster->shifts()->create([
                'shift_type_id' => $values['shift_type_id'], 'shift_date' => $values['shift_date'],
            ]);
            $shift->capacitySnapshot()->create([
                'main_count' => $values['main_count'], 'optional_count' => $values['optional_count'],
            ]);
        }
        $roster->update(['last_generated_at' => null, 'generated_history_fingerprint' => null, 'updated_by' => $admin->id]);
        $this->generator->generate($roster, $admin, deferUndoUntilCommit: true);
    }

    private function clearUndo(Roster $roster): void
    {
        $undo = session()->get(RosterManualEditService::UNDO_KEY);
        if (is_array($undo) && ($undo['roster_id'] ?? null) === $roster->id) {
            session()->forget(RosterManualEditService::UNDO_KEY);
        }
    }
}
