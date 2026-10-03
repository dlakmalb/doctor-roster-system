<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RequestIntervalService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function assignmentRoster(): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $admin = User::factory()->create();

    return [$admin, app(RosterStructureService::class)->create(2026, 10, $admin)];
}

function shiftOn(string $date, string $code): RosterShift
{
    return RosterShift::query()->whereDate('shift_date', $date)
        ->whereHas('shiftType', fn ($query) => $query->where('code', $code))
        ->with('shiftType')->firstOrFail();
}

function generateUrl(): string
{
    return route('rosters.generate', ['year' => 2026, 'month' => 10]);
}

it('requires authentication and an existing Draft roster', function () {
    $this->post(generateUrl())->assertRedirect(route('login'));
    $admin = User::factory()->create();
    $this->actingAs($admin)->post(generateUrl())->assertNotFound();
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $roster->update(['status' => RosterStatus::Final]);
    $this->from(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->post(generateUrl())->assertSessionHasErrors('roster');
    $this->assertDatabaseCount('roster_assignments', 0);
    expect($roster->fresh()->last_generated_at)->toBeNull();
});

it('generates valid Main and Optional assignments and preserves them on repeat', function () {
    [$admin, $roster] = assignmentRoster();
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $assignments = RosterAssignment::query()->with('rosterShift.shiftType')->get();
    expect($assignments->where('role', RosterAssignmentRole::Main)->count())->toBeGreaterThan(0)
        ->and($assignments->where('role', RosterAssignmentRole::Optional)->count())->toBeGreaterThan(0)
        ->and($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->fresh()->last_generated_at)->not->toBeNull()
        ->and($roster->fresh()->updated_by)->toBe($admin->id);

    $eligibility = app(DoctorAssignmentEligibilityService::class);
    foreach ($assignments as $assignment) {
        $required = $assignment->role === RosterAssignmentRole::Main ? $assignment->rosterShift->shiftType->main_count : $assignment->rosterShift->shiftType->optional_count;
        expect($assignment->slot_number)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($required);
    }
    foreach ($assignments->groupBy('doctor_id') as $doctorAssignments) {
        foreach ($doctorAssignments as $assignment) {
            foreach ($doctorAssignments as $other) {
                if ($assignment->id !== $other->id) {
                    expect($eligibility->shiftConflict($assignment->rosterShift, $other->rosterShift))->toBeNull();
                }
            }
        }
    }
    foreach ($assignments->groupBy('roster_shift_id') as $shiftAssignments) {
        expect($shiftAssignments->pluck('doctor_id')->unique()->count())->toBe($shiftAssignments->count());
    }

    $ids = $assignments->modelKeys();
    $this->post(generateUrl())->assertRedirect();
    expect(RosterAssignment::query()->orderBy('id')->pluck('id')->all())->toBe($ids);
});

it('excludes inactive and monthly excluded doctors while retaining other month eligibility', function () {
    [$admin] = assignmentRoster();
    $doctors = Doctor::query()->orderBy('id')->take(3)->get();
    $doctors[0]->update(['is_active' => false]);
    DoctorMonthlyExclusion::create(['doctor_id' => $doctors[1]->id, 'year' => 2026, 'month' => 10]);
    DoctorMonthlyExclusion::create(['doctor_id' => $doctors[2]->id, 'year' => 2026, 'month' => 11]);
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    expect(RosterAssignment::query()->whereIn('doctor_id', [$doctors[0]->id, $doctors[1]->id])->exists())->toBeFalse()
        ->and(RosterAssignment::query()->where('doctor_id', $doctors[2]->id)->exists())->toBeTrue();
});

it('uses real Day-Off intervals and symmetric same-date and night recovery rules', function () {
    assignmentRoster();
    $doctor = Doctor::query()->firstOrFail();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $dayOff = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-06']);
    foreach ([['2026-10-05', 'weekday_night'], ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'], ['2026-10-06', 'weekday_night']] as [$date, $code]) {
        expect($rules->conflicts($doctor, shiftOn($date, $code), false, collect([$dayOff]), collect()))->toContain('day_off_overlap');
    }
    expect($rules->conflicts($doctor, shiftOn('2026-10-07', 'weekday_day'), false, collect([$dayOff]), collect()))->toBe([]);
    $specific = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-07', 'shift_type_id' => ShiftType::where('code', 'weekday_evening')->firstOrFail()->id]);
    $specific->load('shiftType');
    expect($rules->conflicts($doctor, shiftOn('2026-10-07', 'weekday_evening'), false, collect([$specific]), collect()))->toContain('day_off_overlap')
        ->and($rules->conflicts($doctor, shiftOn('2026-10-07', 'weekday_day'), false, collect([$specific]), collect()))->toBe([]);

    $mondayNight = shiftOn('2026-10-05', 'weekday_night');
    $tuesdayDay = shiftOn('2026-10-06', 'weekday_day');
    expect($rules->shiftConflict($mondayNight, $tuesdayDay))->toBe('next_day_night_recovery')
        ->and($rules->shiftConflict($tuesdayDay, $mondayNight))->toBe('next_day_night_recovery')
        ->and($rules->shiftConflict($mondayNight, shiftOn('2026-10-07', 'weekday_day')))->toBeNull()
        ->and($rules->shiftConflict($mondayNight, shiftOn('2026-10-07', 'weekday_night')))->toBe('night_to_night_recovery')
        ->and($rules->shiftConflict($mondayNight, shiftOn('2026-10-08', 'weekday_night')))->toBeNull()
        ->and($rules->shiftConflict($tuesdayDay, shiftOn('2026-10-06', 'weekday_evening')))->toBe('same_start_date')
        ->and($rules->shiftConflict(shiftOn('2026-10-03', 'weekend_day'), shiftOn('2026-10-03', 'weekend_night')))->toBe('same_start_date');
});

it('applies Night recovery after the earlier shift regardless of argument order', function () {
    assignmentRoster();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $cases = [
        ['2026-10-05', 'weekday_night', '2026-10-06', 'weekday_day', 'next_day_night_recovery'],
        ['2026-10-05', 'weekday_night', '2026-10-06', 'weekday_evening', 'next_day_night_recovery'],
        ['2026-10-05', 'weekday_night', '2026-10-06', 'weekday_night', 'next_day_night_recovery'],
        ['2026-10-05', 'weekday_day', '2026-10-06', 'weekday_night', null],
        ['2026-10-05', 'weekday_evening', '2026-10-06', 'weekday_night', null],
        ['2026-10-05', 'weekday_night', '2026-10-07', 'weekday_day', null],
        ['2026-10-05', 'weekday_night', '2026-10-07', 'weekday_evening', null],
        ['2026-10-05', 'weekday_night', '2026-10-07', 'weekday_night', 'night_to_night_recovery'],
        ['2026-10-05', 'weekday_night', '2026-10-08', 'weekday_night', null],
        ['2026-10-03', 'weekend_night', '2026-10-04', 'weekend_day', 'next_day_night_recovery'],
        ['2026-10-03', 'weekend_night', '2026-10-04', 'weekend_night', 'next_day_night_recovery'],
        ['2026-10-03', 'weekend_night', '2026-10-05', 'weekday_day', null],
        ['2026-10-03', 'weekend_night', '2026-10-05', 'weekday_evening', null],
        ['2026-10-03', 'weekend_night', '2026-10-05', 'weekday_night', 'night_to_night_recovery'],
        ['2026-10-03', 'weekend_night', '2026-10-06', 'weekday_night', null],
    ];

    foreach ($cases as [$firstDate, $firstCode, $secondDate, $secondCode, $expected]) {
        $first = shiftOn($firstDate, $firstCode);
        $second = shiftOn($secondDate, $secondCode);
        expect($rules->shiftConflict($first, $second))->toBe($expected)
            ->and($rules->shiftConflict($second, $first))->toBe($expected);
    }
});

it('loads the next date Day-Off when evaluating a month-end overnight shift', function (bool $fullDayOff, int $expectedAssignments) {
    [$admin, $roster] = assignmentRoster();
    $night = shiftOn('2026-10-31', 'weekend_night');
    $roster->shifts()->where('id', '!=', $night->id)->delete();
    $doctor = Doctor::query()->orderBy('id')->firstOrFail();
    Doctor::query()->where('id', '!=', $doctor->id)->update(['is_active' => false]);
    $dayOff = DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-11-01',
        'shift_type_id' => $fullDayOff ? null : ShiftType::query()->where('code', 'weekend_day')->firstOrFail()->id,
    ]);

    $this->assertModelExists($dayOff);
    $dayOff->load('shiftType');
    expect(app(RequestIntervalService::class)->overlaps(
        app(RequestIntervalService::class)->forDate($night->shift_date, $night->shiftType),
        app(RequestIntervalService::class)->forRequest($dayOff),
    ))->toBe($fullDayOff);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    expect(RosterAssignment::query()->where('roster_shift_id', $night->id)->count())->toBe($expectedAssignments);
})->with([
    'Full Day Off overlaps October 31 Night' => [true, 0],
    'November 1 Weekend Day starts at the Night endpoint' => [false, 1],
]);

it('applies only relevant previous-month night history', function () {
    assignmentRoster();
    $doctor = Doctor::query()->firstOrFail();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $firstDay = shiftOn('2026-10-01', 'weekday_day');
    expect($rules->conflicts($doctor, $firstDay, false, collect(), collect(), CarbonImmutable::parse('2026-09-30 20:00:00')))->toContain('next_day_night_recovery')
        ->and($rules->conflicts($doctor, $firstDay, false, collect(), collect(), CarbonImmutable::parse('2026-09-27 20:00:00')))->toBe([])
        ->and($rules->conflicts($doctor, $firstDay, false, collect(), collect()))->toBe([]);
});

it('honors stored previous-month night history during generation', function () {
    [$admin] = assignmentRoster();
    $doctor = Doctor::query()->orderBy('id')->firstOrFail();
    DoctorMonthlyWorkload::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 9,
        'actual_worked_minutes' => 0,
        'most_recent_night_shift_at' => '2026-09-30 20:00:00',
    ]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $firstDateShiftIds = RosterShift::query()->whereDate('shift_date', '2026-10-01')->pluck('id');
    expect(RosterAssignment::query()->where('doctor_id', $doctor->id)->whereIn('roster_shift_id', $firstDateShiftIds)->exists())->toBeFalse();
    $this->assertDatabaseCount('doctor_monthly_workloads', 1);
});

it('persists partial assignments and displays missing slots as errors', function () {
    [$admin] = assignmentRoster();
    Doctor::query()->where('id', '!=', Doctor::query()->min('id'))->update(['is_active' => false]);
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    expect(RosterAssignment::query()->count())->toBeGreaterThan(0);
    $this->assertDatabaseCount('doctor_monthly_workloads', 0);
    $this->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('roster')
            ->where('has_generated', true)
            ->where('status', 'draft')
            ->where('summary.missing_main', fn (int $count): bool => $count > 0)
            ->where('summary.missing_optional', fn (int $count): bool => $count > 0)
            ->where('days.0.shifts.0.main.1.error', true));
});

it('returns assigned names and short codes in both role groups', function () {
    [$admin] = assignmentRoster();
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $this->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('roster')
            ->where('days.0.shifts.0.main.0.doctor.name', fn (string $name): bool => $name !== '')
            ->where('days.0.shifts.0.main.0.doctor.short_code', fn (string $code): bool => $code !== '')
            ->where('summary.filled_main', fn (int $count): bool => $count > 0)
            ->where('summary.filled_optional', fn (int $count): bool => $count > 0));
});
