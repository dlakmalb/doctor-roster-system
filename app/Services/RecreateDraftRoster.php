<?php

namespace App\Services;

use App\Enums\RosterStatus;
use App\Models\MonthlyShiftCapacity;
use App\Models\Roster;
use App\Models\ShiftType;
use App\Models\User;
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
            $this->generator->generate($roster, $admin);

            DB::afterCommit(function () use ($roster, $admin, $configured, $legacyMainCount, $discardedAssignments, $oldShiftIds): void {
                Log::info('Draft roster recreated with updated legacy Weekend Day capacity.', [
                    'roster_id' => $roster->id, 'year' => $roster->year, 'month' => $roster->month,
                    'admin_id' => $admin->id, 'previous_legacy_main_count' => $configured['main_count'],
                    'legacy_main_count' => $legacyMainCount, 'discarded_assignments' => $discardedAssignments,
                    'discarded_shift_ids' => $oldShiftIds,
                ]);
                $undo = session()->get(RosterManualEditService::UNDO_KEY);
                if (is_array($undo) && ($undo['roster_id'] ?? null) === $roster->id) {
                    session()->forget(RosterManualEditService::UNDO_KEY);
                }
            });

            return $roster->refresh();
        });
    }
}
