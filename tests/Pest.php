<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Services\DoctorMonthlyParticipationService;
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
        $participation->saveBaseline($doctor->id, 2026, 9, true);
    }
}

/** @param Collection<int, Doctor>|null $participatingDoctors */
function snapshotRosterParticipation(Roster $roster, ?Collection $participatingDoctors = null): void
{
    app(DoctorMonthlyParticipationService::class)->snapshotRoster(
        $roster,
        $participatingDoctors ?? Doctor::query()->where('is_active', true)->get(),
    );
}
