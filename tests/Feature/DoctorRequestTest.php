<?php

use App\Enums\DoctorRequestType;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorRequest;
use App\Models\ShiftType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function prepareDoctorRequestTest(): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    test()->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    seedInitialHistoryForGeneration();

    return [
        User::factory()->create(),
        Doctor::query()->firstOrFail(),
        ShiftType::query()->get()->keyBy('code'),
    ];
}

function doctorRequestRoute(string $name, array $parameters = []): string
{
    return route($name, ['year' => 2026, 'month' => 10, ...$parameters]);
}

it('creates a full-day Day-Off request', function () {
    [$user, $doctor] = prepareDoctorRequestTest();

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-05',
        'shift_type_id' => null,
    ])->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10]);

    $this->assertDatabaseHas('doctor_requests', [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-05 00:00:00',
        'shift_type_id' => null,
        'created_by' => $user->id,
    ]);
});

it('rejects a request date outside the month in the route', function () {
    [$user, $doctor] = prepareDoctorRequestTest();

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-11-05',
        'shift_type_id' => null,
    ])->assertSessionHasErrors([
        'request_date' => 'The request date must be within the selected month.',
    ]);

    $this->assertDatabaseCount('doctor_requests', 0);
});

it('creates valid weekday and weekend shift-specific Day-Off requests', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-05',
        'shift_type_id' => $shifts['weekday_day']->id,
    ])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-17',
        'shift_type_id' => $shifts['weekend_night']->id,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('doctor_requests', 2);
});

it('rejects invalid weekday and weekend shift combinations', function (string $date, string $shiftCode) {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => $date,
        'shift_type_id' => $shifts[$shiftCode]->id,
    ])->assertSessionHasErrors([
        'shift_type_id' => 'The selected shift is not valid for the request date.',
    ]);

    $this->assertDatabaseCount('doctor_requests', 0);
})->with([
    'weekday with weekend shift' => ['2026-10-05', 'weekend_day'],
    'weekend with weekday shift' => ['2026-10-17', 'weekday_day'],
]);

it('saves a fourth Day-Off date and shows a soft warning', function () {
    [$user, $doctor] = prepareDoctorRequestTest();
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
        DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => $date]);
    }

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-08',
        'shift_type_id' => null,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('doctor_requests', 4);
    $this->actingAs($user)->get(doctorRequestRoute('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'day_off_limit')->count() === 1));
});

it('counts multiple Day-Off requests on one date as one requested date', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    foreach (['weekday_day', 'weekday_evening', 'weekday_night'] as $code) {
        DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05', 'shift_type_id' => $shifts[$code]->id]);
    }
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-06']);

    $this->actingAs($user)->get(doctorRequestRoute('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'day_off_limit')->isEmpty()));
});

it('saves and flags a late Day-Off request', function () {
    [$user, $doctor] = prepareDoctorRequestTest();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00:00'));

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-05',
        'shift_type_id' => null,
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get(doctorRequestRoute('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dayOffRequests.0.is_late', true)
            ->where('warnings.0.type', 'late_request'));
});

it('requires a specific shift for Preferred Work', function () {
    [$user, $doctor] = prepareDoctorRequestTest();

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-05',
        'shift_type_id' => null,
    ])->assertSessionHasErrors([
        'shift_type_id' => 'Preferred Work requests require a specific shift.',
    ]);
});

it('allows more than three Preferred Work dates without a limit warning', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'] as $date) {
        $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
            'doctor_id' => $doctor->id,
            'request_type' => 'preferred_work',
            'request_date' => $date,
            'shift_type_id' => $shifts['weekday_day']->id,
        ])->assertSessionHasNoErrors();
    }

    $this->assertDatabaseCount('doctor_requests', 4);
    $this->actingAs($user)->get(doctorRequestRoute('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'day_off_limit')->isEmpty()));
});

it('rejects an invalid shift for a Preferred Work date', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-17',
        'shift_type_id' => $shifts['weekday_day']->id,
    ])->assertSessionHasErrors('shift_type_id');
});

it('rejects an exact Day-Off and Preferred Work contradiction', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05', 'shift_type_id' => $shifts['weekday_day']->id]);

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-05',
        'shift_type_id' => $shifts['weekday_day']->id,
    ])->assertSessionHasErrors([
        'request_date' => 'This request conflicts with an existing Day-Off or Preferred Work request.',
    ]);
});

it('rejects Preferred Work during a full Day-Off date', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05']);

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-05',
        'shift_type_id' => $shifts['weekday_evening']->id,
    ])->assertSessionHasErrors('request_date');
});

it('detects a night shift starting before a full Day-Off date', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-15']);

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-14',
        'shift_type_id' => $shifts['weekday_night']->id,
    ])->assertSessionHasErrors('request_date');
});

it('detects a night shift starting on a full Day-Off date', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-15']);

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-15',
        'shift_type_id' => $shifts['weekday_night']->id,
    ])->assertSessionHasErrors('request_date');
});

it('allows non-overlapping Day-Off and Preferred Work requests', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05', 'shift_type_id' => $shifts['weekday_day']->id]);

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-05',
        'shift_type_id' => $shifts['weekday_evening']->id,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('doctor_requests', 2);
});

it('rejects equivalent duplicate requests with a clear message', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-05', 'shift_type_id' => $shifts['weekday_night']->id]);

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-05',
        'shift_type_id' => $shifts['weekday_night']->id,
    ])->assertSessionHasErrors([
        'request_date' => 'An equivalent request already exists for this doctor.',
    ]);
});

it('blocks requests for a doctor excluded that month', function () {
    [$user, $doctor] = prepareDoctorRequestTest();
    DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10]);

    $this->actingAs($user)->post(doctorRequestRoute('doctor-requests.store'), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-05',
        'shift_type_id' => null,
    ])->assertSessionHasErrors([
        'doctor_id' => 'This doctor is excluded for the selected month.',
    ]);
});

it('reruns hard validation when editing a request', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    $request = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-05', 'shift_type_id' => $shifts['weekday_day']->id]);

    $this->actingAs($user)->put(doctorRequestRoute('doctor-requests.update', ['doctorRequest' => $request]), [
        'doctor_id' => $doctor->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-10-17',
        'shift_type_id' => $shifts['weekday_day']->id,
    ])->assertSessionHasErrors('shift_type_id');

    expect($request->fresh()->request_date->toDateString())->toBe('2026-10-05');
});

it('updates and deletes requests', function () {
    [$user, $doctor, $shifts] = prepareDoctorRequestTest();
    $request = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05']);

    $this->actingAs($user)->put(doctorRequestRoute('doctor-requests.update', ['doctorRequest' => $request]), [
        'doctor_id' => $doctor->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-06',
        'shift_type_id' => $shifts['weekday_evening']->id,
        'note' => 'Updated',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('doctor_requests', ['id' => $request->id, 'request_date' => '2026-10-06 00:00:00', 'note' => 'Updated']);

    $this->actingAs($user)->delete(doctorRequestRoute('doctor-requests.destroy', ['doctorRequest' => $request]))
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10]);
    $this->assertModelMissing($request);
});
