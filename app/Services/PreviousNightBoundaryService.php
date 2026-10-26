<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyNightBoundarySnapshot;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class PreviousNightBoundaryService
{
    public function __construct(private MonthlyShiftCapacityService $capacities) {}

    /** @return Collection<int, true> Doctor IDs indexed for constant-time membership checks. */
    public function doctorIdsForMonth(int $year, int $month): Collection
    {
        $nightDate = CarbonImmutable::create($year, $month, 1)->startOfDay()->subDay();
        $previousRoster = Roster::query()->where('year', $nightDate->year)->where('month', $nightDate->month)->first();

        if ($previousRoster !== null) {
            if ($previousRoster->status !== RosterStatus::Final) {
                throw ValidationException::withMessages([
                    'roster' => "Finalize {$nightDate->format('F Y')} before resolving its final Night assignments for ".CarbonImmutable::create($year, $month, 1)->format('F Y').'.',
                ]);
            }

            $nightShifts = RosterShift::query()
                ->with(['shiftType', 'assignments', 'capacitySnapshot'])
                ->where('roster_id', $previousRoster->id)
                ->whereDate('shift_date', $nightDate)
                ->whereHas('shiftType', fn ($query) => $query->where('is_overnight', true))
                ->get();

            if ($nightShifts->count() !== 1) {
                throw ValidationException::withMessages([
                    'roster' => "The finalized {$nightDate->format('F j, Y')} roster must contain exactly one Night shift before the next month's roster can be used.",
                ]);
            }

            /** @var RosterShift $nightShift */
            $nightShift = $nightShifts->first();
            try {
                $capacity = $nightShift->savedCapacity();
            } catch (LogicException $exception) {
                throw ValidationException::withMessages(['roster' => $exception->getMessage()]);
            }
            $assignments = $nightShift->assignments;
            $mainAssignments = $assignments->where('role', RosterAssignmentRole::Main);
            $optionalAssignments = $assignments->where('role', RosterAssignmentRole::Optional);
            $doctorIds = $assignments->pluck('doctor_id')->map(fn (int|string $doctorId): int => (int) $doctorId);
            $mainSlots = $mainAssignments->pluck('slot_number')->sort()->values()->all();
            $optionalSlots = $optionalAssignments->pluck('slot_number')->sort()->values()->all();
            $expectedMainSlots = $capacity->main_count > 0 ? range(1, $capacity->main_count) : [];
            $optionalSlotsAreValid = count($optionalSlots) === count(array_unique($optionalSlots))
                && collect($optionalSlots)->every(fn (int $slot): bool => $slot >= 1 && $slot <= $capacity->optional_count);
            if ($mainAssignments->count() !== $capacity->main_count
                || $mainSlots !== $expectedMainSlots
                || ! $optionalSlotsAreValid
                || $doctorIds->count() !== $doctorIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'roster' => "The finalized {$nightDate->format('F j, Y')} Night shift must have all saved Main positions filled, valid Optional assignments, and distinct doctors before the next month's roster can be used.",
                ]);
            }

            return $doctorIds
                ->map(fn (int|string $doctorId): int => (int) $doctorId)
                ->flip();
        }

        $snapshot = DoctorMonthlyNightBoundarySnapshot::query()
            ->with('doctors:id')
            ->where('year', $nightDate->year)
            ->where('month', $nightDate->month)
            ->first();

        if ($snapshot === null || ! $snapshot->night_date->isSameDay($nightDate)) {
            throw ValidationException::withMessages([
                'roster' => "Record the final Night assignments for {$nightDate->format('F Y')} in Initial Setup before generating or validating ".CarbonImmutable::create($year, $month, 1)->format('F Y').'.',
            ]);
        }

        $doctorIds = collect($snapshot->doctors->modelKeys())->map(fn (int|string $doctorId): int => (int) $doctorId);
        if ($doctorIds->isEmpty() || $doctorIds->unique()->count() !== $doctorIds->count()) {
            throw ValidationException::withMessages([
                'roster' => "Initial Setup for {$nightDate->format('F Y')} must record distinct doctors assigned to the final Night shift before generating or validating ".CarbonImmutable::create($year, $month, 1)->format('F Y').'.',
            ]);
        }

        return $doctorIds->flip();
    }

    public function expectedDoctorCountForDate(CarbonImmutable $nightDate): int
    {
        $code = $nightDate->isWeekend() ? 'weekend_night' : 'weekday_night';
        $shiftType = ShiftType::query()->where('code', $code)->first();
        if ($shiftType === null || ! $shiftType->is_active || ! $shiftType->is_overnight) {
            throw ValidationException::withMessages([
                'previous_night_doctor_ids' => "The configured overnight shift type for {$nightDate->format('F j, Y')} is missing or inactive.",
            ]);
        }

        $capacity = $this->capacities->resolve($nightDate->year, $nightDate->month, $shiftType);
        $expectedDoctorCount = $capacity['main_count'] + $capacity['optional_count'];
        if ($expectedDoctorCount < 1) {
            throw ValidationException::withMessages([
                'previous_night_doctor_ids' => "The configured overnight shift type for {$nightDate->format('F j, Y')} has no assignment slots.",
            ]);
        }

        return $expectedDoctorCount;
    }

    /** @param list<int> $doctorIds */
    public function saveInitialSnapshot(int $year, int $month, array $doctorIds): void
    {
        $nightDate = CarbonImmutable::create($year, $month, 1)->startOfDay()->endOfMonth();
        $expectedDoctorCount = $this->expectedDoctorCountForDate($nightDate);
        $submittedDoctorIds = array_map('intval', $doctorIds);
        $doctorIds = array_values(array_unique($submittedDoctorIds));
        if (count($submittedDoctorIds) !== $expectedDoctorCount) {
            throw ValidationException::withMessages([
                'previous_night_doctor_ids' => "Select exactly {$expectedDoctorCount} doctors assigned to the final Night shift on {$nightDate->format('F j, Y')}.",
            ]);
        }
        if (count($doctorIds) !== count($submittedDoctorIds)
            || Doctor::query()->whereIn('id', $doctorIds)->count() !== count($doctorIds)) {
            throw ValidationException::withMessages(['previous_night_doctor_ids' => 'Choose distinct existing doctors from the doctor list for the final Night assignments.']);
        }

        $this->assertNoFinalDependentConflict($year, $month, collect($doctorIds)->flip(), 'previous_night_doctor_ids');

        $snapshot = DoctorMonthlyNightBoundarySnapshot::query()->firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['night_date' => $nightDate],
        );
        if (! $snapshot->night_date->isSameDay($nightDate)) {
            throw ValidationException::withMessages(['previous_night_doctor_ids' => 'The saved previous-Night boundary date does not match this baseline month.']);
        }

        $snapshot->doctors()->sync($doctorIds);
    }

    public function fingerprintForMonth(int $year, int $month): string
    {
        $nightDate = CarbonImmutable::create($year, $month, 1)->startOfDay()->subDay();
        $doctorIds = $this->doctorIdsForMonth($year, $month);

        return $this->fingerprint($nightDate, $doctorIds);
    }

    /** @return Collection<int, true> */
    public function doctorIdsInFinalDependentRoster(int $year, int $month): Collection
    {
        $followingDate = CarbonImmutable::create($year, $month, 1)->startOfDay()->endOfMonth()->addDay();
        $dependentRoster = Roster::query()->where('year', $followingDate->year)->where('month', $followingDate->month)->first();
        if ($dependentRoster?->status !== RosterStatus::Final) {
            return collect();
        }

        return RosterAssignment::query()
            ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $dependentRoster->id)->whereDate('shift_date', $followingDate))
            ->pluck('doctor_id')
            ->map(fn (int|string $doctorId): int => (int) $doctorId)
            ->unique()
            ->flip();
    }

    /** @param Collection<int, true> $doctorIds */
    public function fingerprint(CarbonImmutable $nightDate, Collection $doctorIds): string
    {
        $sortedDoctorIds = $doctorIds->keys()->map(fn (int|string $doctorId): int => (int) $doctorId)->sort()->values();

        return hash('sha256', json_encode([$nightDate->toDateString(), $sortedDoctorIds], JSON_THROW_ON_ERROR));
    }

    /** @param Collection<int, true> $doctorIds */
    public function assertNoFinalDependentConflict(int $year, int $month, Collection $doctorIds, string $errorKey = 'roster'): void
    {
        $nightDate = CarbonImmutable::create($year, $month, 1)->startOfDay()->endOfMonth();
        $followingDate = $nightDate->addDay();
        $dependentRoster = Roster::query()
            ->where('year', $followingDate->year)
            ->where('month', $followingDate->month)
            ->lockForUpdate()
            ->first();

        if ($dependentRoster?->status !== RosterStatus::Final || $doctorIds->isEmpty()) {
            return;
        }

        $conflictingNames = RosterShift::query()
            ->with('assignments.doctor')
            ->where('roster_id', $dependentRoster->id)
            ->whereDate('shift_date', $followingDate)
            ->whereHas('assignments', fn ($query) => $query->whereIn('doctor_id', $doctorIds->keys()))
            ->get()
            ->flatMap(fn (RosterShift $shift): Collection => $shift->assignments
                ->whereIn('doctor_id', $doctorIds->keys())
                ->map(fn ($assignment): string => $assignment->doctor->name))
            ->unique()
            ->values();

        if ($conflictingNames->isNotEmpty()) {
            throw ValidationException::withMessages([
                $errorKey => "This change conflicts with the finalized {$followingDate->format('F Y')} roster on {$followingDate->format('F j')}: ".$conflictingNames->join(', ').'.',
            ]);
        }
    }
}
