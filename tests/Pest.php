<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Services\DoctorMonthlyParticipationService;
use App\Services\InitialWorkloadSetupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function seedInitialHistoryForGeneration(): void
{
    $participation = app(DoctorMonthlyParticipationService::class);
    foreach (Doctor::query()->get() as $doctor) {
        DoctorMonthlyWorkload::query()->firstOrCreate(
            ['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 9],
            ['source' => DoctorMonthlyWorkloadSource::ManualInitial, 'actual_worked_minutes' => 0],
        );
        $participation->saveBaseline($doctor->id, 2026, 9, $doctor->is_active);
    }
    $nightDate = CarbonImmutable::create(2026, 9, 1)->endOfMonth();
    $nightType = ShiftType::query()
        ->where('code', $nightDate->isWeekend() ? 'weekend_night' : 'weekday_night')
        ->firstOrFail();
    $requiredDoctorCount = $nightType->main_count + $nightType->optional_count;
    $previousNightDoctorIds = Doctor::query()
        ->where('is_active', true)
        ->orderBy('id')
        ->limit($requiredDoctorCount)
        ->pluck('id')
        ->all();

    app(InitialWorkloadSetupService::class)->recordNightBoundary(2026, 9, $previousNightDoctorIds);
}

/** @param Collection<int, Doctor>|null $participatingDoctors */
function snapshotRosterParticipation(Roster $roster, ?Collection $participatingDoctors = null, bool $completeMonthEndNight = false): void
{
    app(DoctorMonthlyParticipationService::class)->snapshotRoster(
        $roster,
        $participatingDoctors ?? Doctor::query()->where('is_active', true)->get(),
    );
    if ($roster->status === RosterStatus::Final) {
        $lastDate = CarbonImmutable::create($roster->year, $roster->month, 1)->endOfMonth();
        $nightType = ShiftType::query()->where('code', $lastDate->isWeekend() ? 'weekend_night' : 'weekday_night')->firstOrFail();
        $nightShift = RosterShift::query()->firstOrCreate([
            'roster_id' => $roster->id,
            'shift_date' => $lastDate->toDateString(),
            'shift_type_id' => $nightType->id,
        ]);
        if ($completeMonthEndNight) {
            $assignedDoctorIds = $nightShift->assignments()->pluck('doctor_id')->all();
            $eligibleDoctors = ($participatingDoctors ?? Doctor::query()->where('is_active', true)->get())
                ->reject(fn (Doctor $doctor): bool => in_array($doctor->id, $assignedDoctorIds, true))
                ->values();
            foreach ([
                [RosterAssignmentRole::Main, $nightType->main_count],
                [RosterAssignmentRole::Optional, $nightType->optional_count],
            ] as [$role, $capacity]) {
                for ($slot = 1; $slot <= $capacity; $slot++) {
                    if ($nightShift->assignments()->where('role', $role)->where('slot_number', $slot)->exists()) {
                        continue;
                    }
                    $doctor = $eligibleDoctors->shift();
                    if ($doctor === null) {
                        break;
                    }
                    RosterAssignment::query()->firstOrCreate(
                        ['roster_shift_id' => $nightShift->id, 'role' => $role, 'slot_number' => $slot],
                        ['doctor_id' => $doctor->id],
                    );
                }
            }
        }
    }
}
