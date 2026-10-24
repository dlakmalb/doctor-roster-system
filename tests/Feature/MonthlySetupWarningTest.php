<?php

use App\Enums\DoctorRequestType;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function prepareMonthlyWarningTest(): User
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    test()->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    seedInitialHistoryForGeneration();

    return User::factory()->create();
}

function monthlyWarningUrl(): string
{
    return route('monthly-setup.show', ['year' => 2026, 'month' => 10]);
}

it('derives the late warning from the request creation time', function () {
    $user = prepareMonthlyWarningTest();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    $request = DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-10-05',
    ]);
    $request->forceFill(['created_at' => '2026-09-21 09:00:00'])->save();

    $this->actingAs($user)->get(monthlyWarningUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'late_request')->count() === 1));

    $request->forceFill(['created_at' => '2026-09-20 09:00:00'])->save();
    $this->actingAs($user)->get(monthlyWarningUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'late_request')->isEmpty()));
});

it('derives the Day-Off date-limit warning from current request data', function () {
    $user = prepareMonthlyWarningTest();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    $requests = collect(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'])
        ->map(fn (string $date): DoctorRequest => DoctorRequest::create([
            'doctor_id' => $doctor->id,
            'request_type' => DoctorRequestType::DayOff,
            'request_date' => $date,
        ]));

    $this->actingAs($user)->get(monthlyWarningUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'day_off_limit')->count() === 1));

    $requests->last()?->delete();
    $this->actingAs($user)->get(monthlyWarningUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'day_off_limit')->isEmpty()));
});

it('shows staffing-risk warnings when exclusions leave too few doctors', function () {
    $user = prepareMonthlyWarningTest();
    Doctor::query()->where('is_active', true)->orderBy('short_code')->limit(9)->get()->each(function (Doctor $doctor): void {
        DoctorMonthlyExclusion::create([
            'doctor_id' => $doctor->id,
            'year' => 2026,
            'month' => 10,
        ]);
    });

    $this->actingAs($user)->get(monthlyWarningUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'staffing_risk')->isNotEmpty()));
});

it('shows a staffing-risk warning when overlapping Day-Off requests leave too few doctors', function () {
    $user = prepareMonthlyWarningTest();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    Doctor::query()->where('is_active', true)->orderBy('short_code')->limit(9)->get()->each(function (Doctor $doctor): void {
        DoctorRequest::create([
            'doctor_id' => $doctor->id,
            'request_type' => DoctorRequestType::DayOff,
            'request_date' => '2026-10-05',
        ]);
    });

    $this->actingAs($user)->get(monthlyWarningUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->contains(fn (array $warning): bool => $warning === [
                'type' => 'staffing_risk',
                'message' => 'Oct 5 Weekday Day may have insufficient availability: 5 doctors available for 6 required positions.',
            ])));
});

it('does not show staffing-risk warnings when enough doctors are available', function () {
    $user = prepareMonthlyWarningTest();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));

    $this->actingAs($user)->get(monthlyWarningUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('warnings', fn (Collection $warnings): bool => $warnings->where('type', 'staffing_risk')->isEmpty()));
});
