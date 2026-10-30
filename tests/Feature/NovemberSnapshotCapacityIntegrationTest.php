<?php

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\RosterShiftCapacitySnapshot;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\MonthlyShiftCapacityService;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;

it('generates and regenerates November using saved capacities and preserves October history', function (): void {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    $activeDoctors = Doctor::query()->where('is_active', true)->orderBy('id')->get();
    expect($activeDoctors)->toHaveCount(14);

    $october = app(RosterStructureService::class)->create(2026, 10, $admin);
    $octoberNight = $october->shifts()
        ->whereDate('shift_date', '2026-10-31')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_night'))
        ->firstOrFail();
    foreach ($activeDoctors->take(2) as $index => $doctor) {
        RosterAssignment::create([
            'roster_shift_id' => $octoberNight->id,
            'doctor_id' => $doctor->id,
            'role' => RosterAssignmentRole::Main,
            'slot_number' => $index + 1,
        ]);
    }
    $october->update(['status' => RosterStatus::Final]);
    $octoberSnapshotsBefore = RosterShiftCapacitySnapshot::query()
        ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->orderBy('roster_shift_id')->get(['roster_shift_id', 'main_count', 'optional_count'])->toArray();
    $octoberAssignmentsBefore = RosterAssignment::query()
        ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->orderBy('id')->get(['id', 'roster_shift_id', 'doctor_id', 'role', 'slot_number'])->toArray();
    $octoberNightDoctorIds = $octoberNight->assignments()->pluck('doctor_id')->all();

    $capacityService = app(MonthlyShiftCapacityService::class);
    foreach ([
        'weekday_day' => [3, 2],
        'weekday_evening' => [2, 1],
        'weekday_night' => [2, 0],
        'weekend_day' => [2, 7],
        'weekend_night' => [2, 0],
    ] as $code => [$main, $optional]) {
        $capacityService->create(2026, 11, $code, $main, $optional);
    }
    foreach ($activeDoctors->slice(2, 2) as $doctor) {
        DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 11]);
    }

    $november = app(RosterStructureService::class)->create(2026, 11, $admin);
    $shifts = $november->shifts()->with(['shiftType', 'capacitySnapshot'])->get();
    expect($shifts)->toHaveCount(81)
        ->and($shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count))->toBe(183)
        ->and($shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->optional_count))->toBe(126);

    $generator = app(RosterAssignmentGenerator::class);
    $validation = app(RosterDraftValidationService::class);
    $generator->generate($november, $admin);
    assertNovemberAssignmentsRespectSavedCapacity($november, $activeDoctors->slice(2, 2)->modelKeys(), $octoberNightDoctorIds);

    $assignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $november->id))->get();
    $mainAssignment = $assignments->firstWhere('role', RosterAssignmentRole::Main);
    expect($mainAssignment)->not->toBeNull();
    $mainAssignment->delete();
    $optionalShift = $november->shifts()->with(['shiftType', 'capacitySnapshot', 'assignments'])
        ->get()->first(fn (RosterShift $shift): bool => $shift->savedCapacity()->optional_count > $shift->assignments->where('role', RosterAssignmentRole::Optional)->count());
    if ($optionalShift === null) {
        $optionalShift = $november->shifts()->with(['shiftType', 'capacitySnapshot', 'assignments'])
            ->get()->first(fn (RosterShift $shift): bool => $shift->savedCapacity()->optional_count > 0);
        $optionalAssignment = $optionalShift?->assignments->firstWhere('role', RosterAssignmentRole::Optional);
        $optionalAssignment?->delete();
    }

    $issues = collect($validation->validate($november));
    expect($issues->contains(fn (array $issue): bool => $issue['code'] === 'unfilled_main_slot' && $issue['severity'] === 'Error'))->toBeTrue()
        ->and($issues->contains(fn (array $issue): bool => $issue['code'] === 'unfilled_optional_slot' && $issue['severity'] === 'Warning'))->toBeTrue();

    $generator->regenerate($november->fresh(), $admin);
    assertNovemberAssignmentsRespectSavedCapacity($november, $activeDoctors->slice(2, 2)->modelKeys(), $octoberNightDoctorIds);
    expect(RosterShiftCapacitySnapshot::query()
        ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->orderBy('roster_shift_id')->get(['roster_shift_id', 'main_count', 'optional_count'])->toArray())->toBe($octoberSnapshotsBefore)
        ->and(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
            ->orderBy('id')->get(['id', 'roster_shift_id', 'doctor_id', 'role', 'slot_number'])->toArray())->toBe($octoberAssignmentsBefore);

    $capacityService->create(2027, 1, 'weekend_day', 8, 10);
    $futureRoster = app(RosterStructureService::class)->create(2027, 1, $admin);
    $futureWeekendDay = $futureRoster->shifts()->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->firstOrFail();
    expect($futureWeekendDay->savedCapacity()->main_count)->toBe(8)
        ->and($futureWeekendDay->savedCapacity()->optional_count)->toBe(10);
});

function assertNovemberAssignmentsRespectSavedCapacity(Roster $roster, array $excludedDoctorIds, array $previousNightDoctorIds): void
{
    $shifts = $roster->shifts()->with(['shiftType', 'capacitySnapshot', 'assignments'])->get();
    $assignments = $shifts->flatMap->assignments;
    expect($assignments->whereIn('doctor_id', $excludedDoctorIds))->toBeEmpty();
    $firstDayShiftIds = $shifts->filter(fn (RosterShift $shift): bool => $shift->shift_date->toDateString() === '2026-11-01')->modelKeys();
    $firstDayDoctorIds = RosterAssignment::query()->whereIn('roster_shift_id', $firstDayShiftIds)->pluck('doctor_id');
    expect($firstDayDoctorIds->intersect($previousNightDoctorIds))->toBeEmpty();

    foreach ($shifts as $shift) {
        foreach ([RosterAssignmentRole::Main, RosterAssignmentRole::Optional] as $role) {
            $capacity = $shift->savedCapacityForRole($role);
            foreach ($shift->assignments->where('role', $role) as $assignment) {
                expect($assignment->slot_number)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($capacity);
            }
        }
    }

    $eligibility = app(DoctorAssignmentEligibilityService::class);
    $assignmentsByDoctor = RosterAssignment::query()->with('rosterShift.shiftType')
        ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))
        ->get()->groupBy('doctor_id');
    foreach ($assignmentsByDoctor as $doctorAssignments) {
        foreach ($doctorAssignments as $assignment) {
            foreach ($doctorAssignments as $other) {
                if ($assignment->id === $other->id) {
                    continue;
                }
                expect($eligibility->roleAwareShiftConflict(
                    $assignment->rosterShift,
                    $assignment->role,
                    $other->rosterShift,
                    $other->role,
                ))->toBeNull();
            }
        }
    }
}
