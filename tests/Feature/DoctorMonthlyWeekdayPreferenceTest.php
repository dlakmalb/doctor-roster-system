<?php

use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RosterDraftContext;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;

function monthlyWeekdayPreferenceFixture(): array
{
    test()->travelTo('2026-09-01 09:00:00');
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();

    return [$admin, $roster, $doctor];
}

function monthlyWeekdayPreferenceUrl(string $name, array $extra = []): string
{
    return route($name, ['year' => 2026, 'month' => 10, ...$extra]);
}

it('saves multiple compatible weekdays and isolates preferences by month and shift type', function () {
    [$admin, , $doctor] = monthlyWeekdayPreferenceFixture();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $weekendNight = ShiftType::query()->where('code', 'weekend_night')->firstOrFail();

    $this->actingAs($admin)->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekdayNight->id,
        'weekdays' => [4, 5],
    ])->assertRedirect();
    $this->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekendNight->id,
        'weekdays' => [7],
    ])->assertRedirect();
    $this->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekdayNight->id,
        'weekdays' => [4],
    ])->assertSessionHasErrors('weekdays');

    expect(DoctorMonthlyWeekdayPreference::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 10)->count())->toBe(3)
        ->and(DoctorMonthlyWeekdayPreference::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 11)->count())->toBe(0);
    expect(fn () => DoctorMonthlyWeekdayPreference::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $weekdayNight->id,
        'weekday' => 4,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects invalid, duplicate and shift-incompatible weekday values', function () {
    [$admin, , $doctor] = monthlyWeekdayPreferenceFixture();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();

    $this->actingAs($admin)->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekdayNight->id,
        'weekdays' => [0],
    ])->assertSessionHasErrors('weekdays.0');
    $this->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekdayNight->id,
        'weekdays' => [4, 4],
    ])->assertSessionHasErrors('weekdays.1');
    $this->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekdayNight->id,
        'weekdays' => [7],
    ])->assertSessionHasErrors('weekdays');

    $this->assertDatabaseCount('doctor_monthly_weekday_preferences', 0);
});

it('supports editing and removing records while refusing cross-month URLs', function () {
    [$admin, , $doctor] = monthlyWeekdayPreferenceFixture();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $weekendNight = ShiftType::query()->where('code', 'weekend_night')->firstOrFail();
    $preference = DoctorMonthlyWeekdayPreference::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $weekdayNight->id,
        'weekday' => 4,
    ]);

    $this->actingAs($admin)->put(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.update', ['doctorMonthlyWeekdayPreference' => $preference->id]), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekdayNight->id,
        'weekdays' => [7],
    ])->assertSessionHasErrors('weekdays');
    $this->assertDatabaseHas('doctor_monthly_weekday_preferences', ['id' => $preference->id, 'weekday' => 4]);

    $this->actingAs($admin)->put(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.update', ['doctorMonthlyWeekdayPreference' => $preference->id]), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekendNight->id,
        'weekdays' => [7],
    ])->assertRedirect();
    $this->delete(route('monthly-weekday-preferences.destroy', ['year' => 2026, 'month' => 11, 'doctorMonthlyWeekdayPreference' => $preference->id]))
        ->assertRedirect(route('dashboard'));
    $this->assertDatabaseHas('doctor_monthly_weekday_preferences', ['id' => $preference->id, 'weekday' => 7]);
    $this->delete(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.destroy', ['doctorMonthlyWeekdayPreference' => $preference->id]))
        ->assertRedirect();
    $this->assertDatabaseMissing('doctor_monthly_weekday_preferences', ['id' => $preference->id]);
});

it('keeps weekday preferences soft and does not create unfulfilled Preferred Work warnings', function () {
    [, $roster, $doctor] = monthlyWeekdayPreferenceFixture();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    DoctorMonthlyWeekdayPreference::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $weekdayNight->id,
        'weekday' => 4,
    ]);
    $mondayNight = RosterShift::query()->where('roster_id', $roster->id)
        ->whereDate('shift_date', '2026-10-05')->where('shift_type_id', $weekdayNight->id)->firstOrFail();
    $context = app(RosterDraftContext::class);
    $context->load($roster);

    expect($context->hardReasons($doctor->id, $mondayNight, []))->toBe([])
        ->and(collect(app(RosterDraftValidationService::class)->validate($roster))
            ->contains(fn (array $item): bool => $item['code'] === 'preferred_work_unfulfilled'))->toBeFalse();
});

it('requires authentication and blocks changes to Final rosters while keeping inactive records readable and removable', function () {
    [$admin, $roster, $doctor] = monthlyWeekdayPreferenceFixture();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $inactiveDoctor = Doctor::query()->where('is_active', false)->firstOrFail();
    $preference = DoctorMonthlyWeekdayPreference::create([
        'doctor_id' => $inactiveDoctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $weekdayNight->id,
        'weekday' => 4,
    ]);

    $payload = ['doctor_id' => $doctor->id, 'shift_type_id' => $weekdayNight->id, 'weekdays' => [4]];
    $this->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), $payload)->assertRedirect(route('login'));
    $this->actingAs($admin)->get(monthlyWeekdayPreferenceUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page->has('weekdayPreferences', 1)->where('weekdayPreferences.0.doctor.is_active', false));

    $this->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), ['doctor_id' => $inactiveDoctor->id, 'shift_type_id' => $weekdayNight->id, 'weekdays' => [5]])
        ->assertSessionHasErrors('doctor_id');
    DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $inactiveDoctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $weekdayNight->id,
    ]);
    $this->get(monthlyWeekdayPreferenceUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('weekdayPreferences.0.conflicts_with_restriction', true)
            ->where('warnings', fn ($warnings): bool => collect($warnings)->contains(fn (array $warning): bool => $warning['type'] === 'restricted_weekday_preference')));
    $this->delete(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.destroy', ['doctorMonthlyWeekdayPreference' => $preference->id]))
        ->assertRedirect();
    $preference = DoctorMonthlyWeekdayPreference::create([
        'doctor_id' => $inactiveDoctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $weekdayNight->id,
        'weekday' => 4,
    ]);
    $roster->update(['status' => RosterStatus::Final]);
    $this->post(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.store'), $payload)->assertRedirect();
    $this->put(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.update', ['doctorMonthlyWeekdayPreference' => $preference->id]), [
        'doctor_id' => $doctor->id,
        'shift_type_id' => $weekdayNight->id,
        'weekdays' => [5],
    ])->assertRedirect();
    $this->delete(monthlyWeekdayPreferenceUrl('monthly-weekday-preferences.destroy', ['doctorMonthlyWeekdayPreference' => $preference->id]))->assertRedirect();
    $this->get(monthlyWeekdayPreferenceUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page->has('weekdayPreferences', 1)->where('rosterStatus', 'final'));
    $this->assertDatabaseHas('doctor_monthly_weekday_preferences', ['id' => $preference->id]);
});
