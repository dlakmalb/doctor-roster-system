<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\ShiftType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('renders monthly setup without creating a roster', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('monthly-setup')
            ->where('month.label', 'October 2026')
            ->where('rosterStatus', 'not_started')
            ->where('summary.active_doctors', 14)
            ->has('dayOffRequests', 0)
            ->has('preferredWorkRequests', 0)
            ->has('exclusions', 0));

    $this->assertDatabaseCount('rosters', 0);
});

it('shows only requests belonging to the selected month', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $doctor = Doctor::firstOrFail();
    $shiftType = ShiftType::where('code', 'weekday_day')->firstOrFail();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05']);
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-06', 'shift_type_id' => $shiftType->id]);
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-05']);

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('dayOffRequests', 1)
            ->where('dayOffRequests.0.request_date', '2026-10-05')
            ->has('preferredWorkRequests', 1)
            ->where('preferredWorkRequests.0.request_date', '2026-10-06'));
});

it('keeps historical requests visible when a doctor becomes inactive', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $doctor = Doctor::firstOrFail();
    $request = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05']);
    $doctor->update(['is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dayOffRequests.0.id', $request->id)
            ->where('dayOffRequests.0.doctor.is_active', false)
            ->has('doctors', 13));
});

it('rejects invalid month route values', function () {
    $this->actingAs(User::factory()->create())
        ->get('/monthly-setup/2026/13')
        ->assertNotFound();

    $this->get('/monthly-setup/0000/10')->assertNotFound();
});

it('shows current and next month roster statuses without creating rosters', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
    Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('months.0.label', 'October 2026')
            ->where('months.0.status', 'not_started')
            ->where('months.1.label', 'November 2026')
            ->where('months.1.status', 'final'));

    $this->assertDatabaseCount('rosters', 1);
});
