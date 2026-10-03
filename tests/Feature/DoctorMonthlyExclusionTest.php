<?php

use App\Enums\DoctorRequestType;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorRequest;
use App\Models\User;
use Database\Seeders\DoctorsSeeder;

function prepareMonthlyExclusionTest(): array
{
    test()->seed(DoctorsSeeder::class);

    return [User::factory()->create(), Doctor::query()->firstOrFail()];
}

function monthlyExclusionRoute(string $name, array $parameters = []): string
{
    return route($name, ['year' => 2026, 'month' => 10, ...$parameters]);
}

it('adds a monthly exclusion with an optional note', function () {
    [$user, $doctor] = prepareMonthlyExclusionTest();

    $this->actingAs($user)->post(monthlyExclusionRoute('monthly-exclusions.store'), [
        'doctor_id' => $doctor->id,
        'note' => 'Study leave',
    ])->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10]);

    $this->assertDatabaseHas('doctor_monthly_exclusions', [
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'note' => 'Study leave',
        'created_by' => $user->id,
    ]);
});

it('does not deactivate a doctor when adding a monthly exclusion', function () {
    [$user, $doctor] = prepareMonthlyExclusionTest();

    $this->actingAs($user)->post(monthlyExclusionRoute('monthly-exclusions.store'), [
        'doctor_id' => $doctor->id,
    ])->assertSessionHasNoErrors();

    expect($doctor->fresh()->is_active)->toBeTrue();
});

it('blocks an exclusion while the doctor has requests in that month', function () {
    [$user, $doctor] = prepareMonthlyExclusionTest();
    DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-10-05',
    ]);

    $this->actingAs($user)->post(monthlyExclusionRoute('monthly-exclusions.store'), [
        'doctor_id' => $doctor->id,
    ])->assertSessionHasErrors([
        'doctor_id' => 'Remove this doctor\'s existing requests for the month before excluding them.',
    ]);

    $this->assertDatabaseCount('doctor_monthly_exclusions', 0);
});

it('allows exclusion when the doctor only has requests in another month', function () {
    [$user, $doctor] = prepareMonthlyExclusionTest();
    DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-11-05',
    ]);

    $this->actingAs($user)->post(monthlyExclusionRoute('monthly-exclusions.store'), [
        'doctor_id' => $doctor->id,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('doctor_monthly_exclusions', 1);
});

it('removes a monthly exclusion', function () {
    [$user, $doctor] = prepareMonthlyExclusionTest();
    $exclusion = DoctorMonthlyExclusion::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
    ]);

    $this->actingAs($user)->delete(monthlyExclusionRoute('monthly-exclusions.destroy', [
        'doctorMonthlyExclusion' => $exclusion,
    ]))->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10]);

    $this->assertModelMissing($exclusion);
});
