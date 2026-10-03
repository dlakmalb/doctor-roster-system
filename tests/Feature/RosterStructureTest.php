<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function prepareRosterStructureTest(): User
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);

    return User::factory()->create();
}

function rosterRoute(string $name, int $year = 2026, int $month = 10): string
{
    return route($name, ['year' => $year, 'month' => $month]);
}

it('requires authentication to create or view a roster', function () {
    $this->post(rosterRoute('rosters.store'))->assertRedirect(route('login'));
    $this->get(rosterRoute('rosters.show'))->assertRedirect(route('login'));

    $this->assertDatabaseCount('rosters', 0);
});

it('creates one draft structure with audit fields and no assignments or workloads', function () {
    $user = prepareRosterStructureTest();

    $this->actingAs($user)->post(rosterRoute('rosters.store'))
        ->assertRedirect(rosterRoute('rosters.show'));

    $roster = Roster::query()->sole();
    expect($roster->year)->toBe(2026)
        ->and($roster->month)->toBe(10)
        ->and($roster->status)->toBe(RosterStatus::Draft);
    $this->assertDatabaseHas('rosters', [
        'id' => $roster->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'last_generated_at' => null,
        'finalized_at' => null,
    ]);
    $this->assertDatabaseCount('roster_shifts', 84);
    $this->assertDatabaseCount('roster_assignments', 0);
    $this->assertDatabaseCount('doctor_monthly_workloads', 0);
});

it('creates the expected weekday and weekend shifts for every start date', function () {
    $user = prepareRosterStructureTest();

    app(RosterStructureService::class)->create(2026, 10, $user);

    $byDate = RosterShift::query()->with('shiftType')->get()
        ->groupBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString())
        ->map(fn ($shifts): array => $shifts->pluck('shiftType.code')->sort()->values()->all());
    expect($byDate)->toHaveCount(31);
    expect($byDate['2026-10-02'])->toBe(['weekday_day', 'weekday_evening', 'weekday_night']);
    expect($byDate['2026-10-03'])->toBe(['weekend_day', 'weekend_night']);
    expect($byDate['2026-10-04'])->toBe(['weekend_day', 'weekend_night']);
    expect($byDate['2026-10-05'])->toBe(['weekday_day', 'weekday_evening', 'weekday_night']);

    for ($day = 1; $day <= 31; $day++) {
        $date = CarbonImmutable::create(2026, 10, $day);
        $expected = $date->isWeekend()
            ? ['weekend_day', 'weekend_night']
            : ['weekday_day', 'weekday_evening', 'weekday_night'];
        expect($byDate[$date->toDateString()])->toBe($expected);
    }

    expect($byDate->has('2026-09-30'))->toBeFalse();
    expect($byDate['2026-10-31'])->toContain('weekend_night');
});

it('includes leap day and a month-end weekday night without adding next-month starts', function () {
    $user = prepareRosterStructureTest();

    app(RosterStructureService::class)->create(2024, 2, $user);

    $codes = RosterShift::query()->with('shiftType')->whereDate('shift_date', '2024-02-29')->get()
        ->pluck('shiftType.code')->sort()->values()->all();
    expect($codes)->toBe(['weekday_day', 'weekday_evening', 'weekday_night']);
    $this->assertDatabaseCount('roster_shifts', 79);
    $this->assertDatabaseMissing('roster_shifts', ['shift_date' => '2024-03-01']);
});

it('leaves an existing draft or final roster and its shifts untouched', function (RosterStatus $status) {
    $user = prepareRosterStructureTest();
    $roster = app(RosterStructureService::class)->create(2026, 10, $user);
    $shiftIds = $roster->shifts()->pluck('id')->all();
    $roster->update(['status' => $status]);

    $this->actingAs($user)->post(rosterRoute('rosters.store'))
        ->assertRedirect(rosterRoute('rosters.show'));

    $this->assertDatabaseCount('rosters', 1);
    $this->assertDatabaseCount('roster_shifts', 84);
    expect($roster->shifts()->pluck('id')->all())->toBe($shiftIds);
    expect($roster->fresh()->status)->toBe($status);
})->with([RosterStatus::Draft, RosterStatus::Final]);

it('fails cleanly when a required shift type is missing or inactive', function (string $condition) {
    $user = prepareRosterStructureTest();
    $shiftType = ShiftType::query()->where('code', 'weekend_night')->firstOrFail();
    $condition === 'missing' ? $shiftType->delete() : $shiftType->update(['is_active' => false]);

    $this->actingAs($user)->from(rosterRoute('monthly-setup.show'))
        ->post(rosterRoute('rosters.store'))
        ->assertRedirect(rosterRoute('monthly-setup.show'))
        ->assertSessionHasErrors(['roster']);

    $this->assertDatabaseCount('rosters', 0);
    $this->assertDatabaseCount('roster_shifts', 0);
})->with(['missing', 'inactive']);

it('rolls back the roster and its shifts when a shift insert fails', function () {
    $user = prepareRosterStructureTest();
    $attempted = 0;
    RosterShift::creating(function () use (&$attempted): void {
        $attempted++;
        if ($attempted === 3) {
            throw new RuntimeException('Shift insert failed.');
        }
    });

    expect(fn () => app(RosterStructureService::class)->create(2026, 10, $user))
        ->toThrow(RuntimeException::class, 'Shift insert failed.');

    $this->assertDatabaseCount('rosters', 0);
    $this->assertDatabaseCount('roster_shifts', 0);
});

it('shows chronological shifts, required positions, and the overnight end date', function () {
    $user = prepareRosterStructureTest();
    $roster = app(RosterStructureService::class)->create(2026, 10, $user);
    $roster->shifts()->whereDate('shift_date', '2026-10-01')->delete();
    foreach (['weekday_night', 'weekday_evening', 'weekday_day'] as $code) {
        $roster->shifts()->create([
            'shift_type_id' => ShiftType::query()->where('code', $code)->firstOrFail()->id,
            'shift_date' => '2026-10-01',
        ]);
    }

    $this->actingAs($user)->get(rosterRoute('rosters.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('roster')
            ->where('month.label', 'October 2026')
            ->where('status', 'draft')
            ->where('summary.shifts', 84)
            ->where('summary.main_positions', 243)
            ->where('summary.optional_positions', 93)
            ->has('days', 31)
            ->where('days.0.date', '2026-10-01')
            ->where('days.0.shifts.0.code', 'weekday_day')
            ->where('days.0.shifts.1.code', 'weekday_evening')
            ->where('days.0.shifts.2.code', 'weekday_night')
            ->where('days.0.shifts.0.main_count', 4)
            ->where('days.0.shifts.0.optional_count', 2)
            ->where('days.0.shifts.2.end_date_label', 'Oct 2')
            ->where('days.30.shifts.0.code', 'weekend_day')
            ->where('days.30.shifts.1.code', 'weekend_night')
            ->where('days.30.shifts.1.end_date_label', 'Nov 1'));
});

it('reports draft on the dashboard and monthly setup after creation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
    $user = prepareRosterStructureTest();
    app(RosterStructureService::class)->create(2026, 10, $user);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('months.0.status', 'draft'));
    $this->get(rosterRoute('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page->where('rosterStatus', 'draft'));
});

it('keeps monthly requests and exclusions when creating a roster', function () {
    $user = prepareRosterStructureTest();
    $doctor = Doctor::query()->firstOrFail();
    $dayOff = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05']);
    $preferred = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-06', 'shift_type_id' => ShiftType::where('code', 'weekday_day')->firstOrFail()->id]);
    $exclusion = DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10]);

    app(RosterStructureService::class)->create(2026, 10, $user);

    $this->assertModelExists($dayOff);
    $this->assertModelExists($preferred);
    $this->assertModelExists($exclusion);
    $this->assertDatabaseCount('doctor_requests', 2);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 1);
});

it('rejects invalid months and does not show a nonexistent roster', function () {
    $user = prepareRosterStructureTest();

    $this->actingAs($user)->post('/rosters/2026/13')->assertNotFound();
    $this->post('/rosters/0000/10')->assertNotFound();
    $this->get(rosterRoute('rosters.show'))->assertNotFound();

    $this->assertDatabaseCount('rosters', 0);
});
