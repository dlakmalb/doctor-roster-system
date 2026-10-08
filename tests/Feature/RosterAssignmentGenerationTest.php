<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RequestIntervalService;
use App\Services\RosterAssignmentRecoveryService;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function assignmentRoster(?callable $prepareDoctors = null): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    if ($prepareDoctors !== null) {
        $prepareDoctors();
    }

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

it('keeps missing Main errors and Optional warnings before generation while exposing an empty draft', function () {
    [$admin, $roster] = assignmentRoster();
    $issues = app(RosterDraftValidationService::class)->validate($roster);

    expect(collect($issues)->where('severity', 'Error')->isNotEmpty())
        ->toBeTrue()
        ->and(collect($issues)->contains(fn (array $issue): bool => $issue['code'] === 'unfilled_main_slot' && $issue['severity'] === 'Error'))->toBeTrue()
        ->and(collect($issues)->contains(fn (array $issue): bool => $issue['code'] === 'unfilled_optional_slot' && $issue['severity'] === 'Warning'))->toBeTrue();

    $this->actingAs($admin)->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('roster')
            ->where('has_generated', false)
            ->where('has_assignments', false)
            ->where('summary.filled_main', 0)
            ->where('summary.filled_optional', 0)
            ->where('summary.main_positions', fn (int $count): bool => $count > 0)
            ->where('summary.optional_positions', fn (int $count): bool => $count > 0)
            ->where('summary.missing_main', fn (int $count): bool => $count > 0)
            ->where('conflicts.0.code', 'unfilled_main_slot'));
});

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
    $inactiveDoctor = Doctor::query()->where('is_active', false)->firstOrFail();
    expect(RosterAssignment::query()->where('doctor_id', $inactiveDoctor->id)->exists())->toBeFalse();
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

it('keeps every Main position and fills only the eligible Optional positions up to capacity', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        foreach (Doctor::query()->where('is_active', true)->orderBy('short_code')->get()->slice(8) as $doctor) {
            DoctorRequest::create([
                'doctor_id' => $doctor->id,
                'request_type' => DoctorRequestType::DayOff,
                'request_date' => '2026-10-03',
            ]);
        }
    });
    $shift = shiftOn('2026-10-03', 'weekend_day');
    $roster->shifts()->where('id', '!=', $shift->id)->delete();

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();

    $assignments = $shift->assignments()->get();
    $issues = collect(app(RosterDraftValidationService::class)->validate($roster));
    expect($assignments->where('role', RosterAssignmentRole::Main))->toHaveCount(3)
        ->and($assignments->where('role', RosterAssignmentRole::Optional))->toHaveCount(5)
        ->and($issues->where('code', 'unfilled_main_slot'))->toBeEmpty()
        ->and($issues->where('code', 'unfilled_optional_slot')->where('severity', 'Warning'))->toHaveCount(2)
        ->and($issues->where('severity', 'Error'))->toBeEmpty();
});

it('preserves the creation-time participation snapshot during generation and regeneration', function () {
    [$admin, $roster] = assignmentRoster();
    $snapshot = DoctorMonthlyParticipation::query()
        ->where('year', 2026)
        ->where('month', 10)
        ->orderBy('doctor_id')
        ->get(['doctor_id', 'is_participating'])
        ->toArray();
    expect($snapshot)->toHaveCount(Doctor::query()->count())
        ->and(collect($snapshot)->where('is_participating', true))->toHaveCount(14)
        ->and(collect($snapshot)->where('is_participating', false))->toHaveCount(1);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    expect(DoctorMonthlyParticipation::query()->where('year', 2026)->where('month', 10)
        ->orderBy('doctor_id')->get(['doctor_id', 'is_participating'])->toArray())->toBe($snapshot);

    $this->post(route('rosters.regenerate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    expect(DoctorMonthlyParticipation::query()->where('year', 2026)->where('month', 10)
        ->orderBy('doctor_id')->get(['doctor_id', 'is_participating'])->toArray())->toBe($snapshot);
});

it('blocks regeneration when global active status no longer matches the Draft participation snapshot', function () {
    [$admin, $roster] = assignmentRoster();
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $assignments = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $generatedAt = $roster->fresh()->last_generated_at;
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    $doctor->update(['is_active' => false]);

    $this->from(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->post(route('rosters.regenerate', ['year' => 2026, 'month' => 10]))
        ->assertSessionHasErrors([
            'roster' => 'Active doctor statuses no longer match this Draft roster’s saved participation. Restore the active statuses to match the saved population before generating or regenerating.',
        ]);

    expect(DoctorMonthlyParticipation::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 10)->firstOrFail()->is_participating)->toBeTrue()
        ->and(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($assignments)
        ->and($roster->fresh()->last_generated_at->equalTo($generatedAt))->toBeTrue();
});

it('fills all Main positions and as many Optional positions as possible in a 14-doctor month', function () {
    [$admin, $roster] = assignmentRoster();
    $recovery = app(RosterAssignmentRecoveryService::class);
    app()->instance(RosterAssignmentRecoveryService::class, $recovery);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $started = microtime(true);
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $elapsed = microtime(true) - $started;
    if (getenv('ROSTER_BENCHMARK')) {
        $diagnostics = $recovery->diagnostics();
        fwrite(STDERR, 'ROSTER_BENCHMARK '.json_encode(['seconds' => $elapsed, 'queries' => $queries, 'totals' => $diagnostics['totals'], 'max_vacancy_states' => max(array_column($diagnostics['vacancies'], 'states')), 'unfilled' => count($recovery->unfilledDiagnostics())]).PHP_EOL);
    }

    $shifts = $roster->shifts()->with('shiftType')->get();
    $requiredMain = $shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->main_count);
    $requiredOptional = $shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->optional_count);
    $optionalAssignmentCount = RosterAssignment::query()->where('role', RosterAssignmentRole::Optional)->count();
    $optionalVacancyWarnings = collect(app(RosterDraftValidationService::class)->validate($roster))
        ->where('code', 'unfilled_optional_slot')->where('severity', 'Warning');
    expect(RosterAssignment::query()->where('role', RosterAssignmentRole::Main)->count())->toBe($requiredMain)
        ->and($optionalAssignmentCount)->toBeLessThanOrEqual($requiredOptional)
        ->and($optionalVacancyWarnings)->toHaveCount($requiredOptional - $optionalAssignmentCount);
    expect(collect(app(RosterDraftValidationService::class)->validate($roster))->where('severity', 'Error')->count())->toBe(0);
});

it('keeps the feasible month complete across randomized regeneration', function () {
    [$admin, $roster] = assignmentRoster();
    $recovery = app(RosterAssignmentRecoveryService::class);
    app()->instance(RosterAssignmentRecoveryService::class, $recovery);
    $timings = [];
    $maximumStates = 0;
    $maximumDepth = 0;
    $totalStates = 0;
    $limitHits = 0;
    $unresolved = 0;
    $shifts = $roster->shifts()->with('shiftType')->get();
    $requiredMain = $shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->main_count);
    $requiredOptional = $shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->optional_count);
    $this->actingAs($admin);

    for ($run = 0; $run < 20; $run++) {
        $route = $run === 0 ? 'rosters.generate' : 'rosters.regenerate';
        $started = microtime(true);
        $this->post(route($route, ['year' => 2026, 'month' => 10]))->assertRedirect();
        $timings[] = microtime(true) - $started;
        $diagnostics = $recovery->diagnostics();
        $maximumStates = max($maximumStates, ...array_column($diagnostics['vacancies'], 'states'));
        $maximumDepth = max($maximumDepth, $diagnostics['totals']['max_depth']);
        $totalStates += $diagnostics['totals']['states'];
        $limitHits += $diagnostics['totals']['explored_state_limit'] + $diagnostics['totals']['chain_depth_limit'];
        $unresolved += count($recovery->unfilledDiagnostics());

        expect(RosterAssignment::query()->where('role', RosterAssignmentRole::Main)->count())->toBe($requiredMain, "Run $run has missing Main positions.");
        expect(RosterAssignment::query()->where('role', RosterAssignmentRole::Optional)->count())->toBeLessThanOrEqual($requiredOptional, "Run $run exceeds Optional capacity.");
        expect(collect(app(RosterDraftValidationService::class)->validate($roster))->where('severity', 'Error')->count())->toBe(0, "Run $run has hard validation Errors.");
    }
    if (getenv('ROSTER_BENCHMARK')) {
        fwrite(STDERR, 'ROSTER_20_RUN '.json_encode(['fastest' => min($timings), 'slowest' => max($timings), 'average' => array_sum($timings) / count($timings), 'max_vacancy_states' => $maximumStates, 'max_depth' => $maximumDepth, 'total_states' => $totalStates, 'limit_hits' => $limitHits, 'unresolved' => $unresolved]).PHP_EOL);
    }
});

it('excludes inactive and monthly excluded doctors while retaining other month eligibility', function () {
    [$admin] = assignmentRoster();
    $inactiveDoctor = Doctor::query()->where('is_active', false)->firstOrFail();
    $activeDoctors = Doctor::query()->where('is_active', true)->orderBy('short_code')->take(2)->get();
    DoctorMonthlyExclusion::create(['doctor_id' => $activeDoctors[0]->id, 'year' => 2026, 'month' => 10]);
    DoctorMonthlyExclusion::create(['doctor_id' => $activeDoctors[1]->id, 'year' => 2026, 'month' => 11]);
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    expect(RosterAssignment::query()->where('doctor_id', $inactiveDoctor->id)->exists())->toBeFalse()
        ->and(RosterAssignment::query()->where('doctor_id', $activeDoctors[0]->id)->exists())->toBeFalse()
        ->and(RosterAssignment::query()->where('doctor_id', $activeDoctors[1]->id)->exists())->toBeTrue();
});

it('uses real Day-Off intervals and symmetric same-date and night recovery rules', function () {
    assignmentRoster();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
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
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    });
    $night = shiftOn('2026-10-31', 'weekend_night');
    $roster->shifts()->where('id', '!=', $night->id)->delete();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
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
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $firstDay = shiftOn('2026-10-01', 'weekday_day');
    expect($rules->conflicts($doctor, $firstDay, false, collect(), collect(), CarbonImmutable::parse('2026-09-30 20:00:00')))->toContain('next_day_night_recovery')
        ->and($rules->conflicts($doctor, $firstDay, false, collect(), collect(), CarbonImmutable::parse('2026-09-27 20:00:00')))->toBe([])
        ->and($rules->conflicts($doctor, $firstDay, false, collect(), collect()))->toBe([]);
});

it('honors stored previous-month night history during generation', function () {
    [$admin] = assignmentRoster();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    DoctorMonthlyWorkload::query()->updateOrCreate(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 9], [
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 9,
        'actual_worked_minutes' => 0,
        'most_recent_night_shift_at' => '2026-09-30 20:00:00',
    ]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $firstDateShiftIds = RosterShift::query()->whereDate('shift_date', '2026-10-01')->pluck('id');
    expect(RosterAssignment::query()->where('doctor_id', $doctor->id)->whereIn('roster_shift_id', $firstDateShiftIds)->exists())->toBeFalse();
    $this->assertDatabaseCount('doctor_monthly_workloads', Doctor::query()->count());
});

it('persists partial assignments and distinguishes missing Main and Optional slots', function () {
    [$admin] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    });
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    expect(RosterAssignment::query()->count())->toBeGreaterThan(0);
    $this->assertDatabaseCount('doctor_monthly_workloads', Doctor::query()->count());
    $this->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('roster')
            ->where('has_generated', true)
            ->where('status', 'draft')
            ->where('summary.missing_main', fn (int $count): bool => $count > 0)
            ->where('summary.missing_optional', fn (int $count): bool => $count > 0)
            ->where('conflicts.0.code', 'unfilled_main_slot')
            ->where('days.0.shifts.0.main.1.severity', 'Error')
            ->where('days.0.shifts.0.optional.0.severity', 'Warning'));
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
