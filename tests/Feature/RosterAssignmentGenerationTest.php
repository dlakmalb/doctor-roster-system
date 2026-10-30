<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\InitialWorkloadSetupService;
use App\Services\PreviousNightBoundaryService;
use App\Services\RequestIntervalService;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterAssignmentRecoveryService;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function assignmentRoster(?callable $prepareDoctors = null, ?callable $prepareShiftTypes = null): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    if ($prepareDoctors !== null) {
        $prepareDoctors();
    }
    if ($prepareShiftTypes !== null) {
        $prepareShiftTypes();
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

function expectGeneratedAssignmentsRoleAwareConflictFree(): void
{
    $eligibility = app(DoctorAssignmentEligibilityService::class);
    $assignmentsByDoctor = RosterAssignment::query()->with('rosterShift.shiftType')->get()->groupBy('doctor_id');

    foreach ($assignmentsByDoctor as $assignments) {
        foreach ($assignments as $assignment) {
            foreach ($assignments as $other) {
                if ($assignment->id !== $other->id) {
                    expect($eligibility->roleAwareShiftConflict(
                        $assignment->rosterShift,
                        $assignment->role,
                        $other->rosterShift,
                        $other->role,
                    ))->toBeNull();
                }
            }
        }
    }
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
        $capacity = $assignment->rosterShift->savedCapacity();
        $required = $assignment->role === RosterAssignmentRole::Main ? $capacity->main_count : $capacity->optional_count;
        expect($assignment->slot_number)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($required);
    }
    foreach ($assignments->groupBy('doctor_id') as $doctorAssignments) {
        foreach ($doctorAssignments as $assignment) {
            foreach ($doctorAssignments as $other) {
                if ($assignment->id !== $other->id) {
                    expect($eligibility->roleAwareShiftConflict($assignment->rosterShift, $assignment->role, $other->rosterShift, $other->role))->toBeNull();
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

it('generates October positions from saved capacities after global shift defaults change', function () {
    [$admin, $roster] = assignmentRoster();
    ShiftType::query()->update(['main_count' => 1, 'optional_count' => 0]);

    app(RosterAssignmentGenerator::class)->generate($roster, $admin);

    $shifts = $roster->shifts()->with(['shiftType', 'assignments', 'capacitySnapshot'])->get();
    expect($shifts)->toHaveCount(84)
        ->and($shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count))->toBe(243)
        ->and($shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->optional_count))->toBe(129);
    foreach ($shifts as $shift) {
        expect($shift->assignments->where('role', RosterAssignmentRole::Main)->count())
            ->toBe($shift->savedCapacity()->main_count)
            ->and($shift->assignments->where('role', RosterAssignmentRole::Optional)->count())
            ->toBeLessThanOrEqual($shift->savedCapacity()->optional_count);
    }
});

it('fails validation and generation safely when a roster shift capacity snapshot is missing', function () {
    [$admin, $roster] = assignmentRoster();
    $shift = shiftOn('2026-10-01', 'weekday_day');
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $shift->id)->delete();

    $issues = app(RosterDraftValidationService::class)->validate($roster);

    expect(collect($issues)->contains(fn (array $issue): bool => $issue['code'] === 'invalid_capacity_snapshot' && $issue['severity'] === 'Error'))->toBeTrue();
    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))
        ->toThrow(ValidationException::class, 'is missing its capacity snapshot');
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->exists())->toBeFalse();
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
    $optionalCapacity = (int) ShiftType::query()->whereKey($shift->shift_type_id)->value('optional_count');
    expect($assignments->where('role', RosterAssignmentRole::Main))->toHaveCount(3)
        ->and($assignments->where('role', RosterAssignmentRole::Optional))->toHaveCount($optionalCapacity)
        ->and($issues->where('code', 'unfilled_main_slot'))->toBeEmpty()
        ->and($issues->where('code', 'unfilled_optional_slot')->where('severity', 'Warning'))->toBeEmpty()
        ->and($issues->where('code', 'hard_conflict'))->toBeEmpty();
    expectGeneratedAssignmentsRoleAwareConflictFree();
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
    $requiredMain = $shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count);
    $requiredOptional = $shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->optional_count);
    $optionalAssignmentCount = RosterAssignment::query()->where('role', RosterAssignmentRole::Optional)->count();
    $optionalVacancyWarnings = collect(app(RosterDraftValidationService::class)->validate($roster))
        ->where('code', 'unfilled_optional_slot')->where('severity', 'Warning');
    expect(RosterAssignment::query()->where('role', RosterAssignmentRole::Main)->count())->toBe($requiredMain)
        ->and($optionalAssignmentCount)->toBeLessThanOrEqual($requiredOptional)
        ->and($optionalVacancyWarnings)->toHaveCount($requiredOptional - $optionalAssignmentCount);
    expectGeneratedAssignmentsRoleAwareConflictFree();
});

it('keeps Main complete and Optional coverage high with a 13-doctor exclusion', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        $doctor = Doctor::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10]);
    });
    $recovery = app(RosterAssignmentRecoveryService::class);
    app()->instance(RosterAssignmentRecoveryService::class, $recovery);

    $started = microtime(true);
    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $elapsed = microtime(true) - $started;
    $diagnostics = $recovery->diagnostics();
    $shifts = $roster->shifts()->with('shiftType')->get();
    $requiredMain = $shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count);
    $requiredOptional = $shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->optional_count);
    $mainCount = RosterAssignment::query()->where('role', RosterAssignmentRole::Main)->count();
    $optionalCount = RosterAssignment::query()->where('role', RosterAssignmentRole::Optional)->count();
    $issues = collect(app(RosterDraftValidationService::class)->validate($roster));

    if (getenv('ROSTER_BENCHMARK')) {
        fwrite(STDERR, 'ROSTER_13_BENCHMARK '.json_encode([
            'seconds' => $elapsed,
            'totals' => $diagnostics['totals'],
            'unfilled' => count($recovery->unfilledDiagnostics()),
            'main_filled' => $mainCount,
            'main_required' => $requiredMain,
            'optional_filled' => $optionalCount,
            'optional_warnings' => $issues->where('code', 'unfilled_optional_slot')->where('severity', 'Warning')->count(),
            'validation_errors' => $issues->where('severity', 'Error')->count(),
        ]).PHP_EOL);
    }

    expect($mainCount)->toBe($requiredMain)
        ->and($optionalCount)->toBeGreaterThanOrEqual(99)
        ->and($optionalCount)->toBeLessThanOrEqual($requiredOptional)
        ->and($issues->where('code', 'unfilled_optional_slot')->where('severity', 'Warning')->count())->toBe($requiredOptional - $optionalCount);
    expectGeneratedAssignmentsRoleAwareConflictFree();
});

it('fills feasible Main and Optional positions despite a Day-Off request and monthly exclusion', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        $excludedDoctor = Doctor::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        DoctorMonthlyExclusion::create(['doctor_id' => $excludedDoctor->id, 'year' => 2026, 'month' => 10]);
    });
    $shift = shiftOn('2026-10-03', 'weekend_day');
    $roster->shifts()->where('id', '!=', $shift->id)->delete();
    foreach (Doctor::query()->where('is_active', true)->orderBy('id')->take(8)->get() as $doctor) {
        DoctorRequest::create([
            'doctor_id' => $doctor->id,
            'request_type' => DoctorRequestType::DayOff,
            'request_date' => $shift->shift_date,
            'shift_type_id' => $shift->shift_type_id,
        ]);
    }

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();

    $assignments = RosterAssignment::query()->where('roster_shift_id', $shift->id)->get();
    $excludedDoctor = DoctorMonthlyExclusion::query()->where('year', 2026)->where('month', 10)->firstOrFail()->doctor_id;
    $issues = collect(app(RosterDraftValidationService::class)->validate($roster));
    $capacity = $shift->savedCapacity();
    expect($assignments->where('role', RosterAssignmentRole::Main))->toHaveCount($capacity->main_count)
        ->and($assignments->where('role', RosterAssignmentRole::Optional))->toHaveCount($capacity->optional_count)
        ->and($assignments->pluck('doctor_id')->unique())->toHaveCount($assignments->count())
        ->and($assignments->pluck('doctor_id'))->not->toContain($excludedDoctor)
        ->and($issues->where('code', 'unfilled_optional_slot')->where('severity', 'Warning'))->toBeEmpty();
    expectGeneratedAssignmentsRoleAwareConflictFree();
});

it('rejects existing Main or Optional assignments for a monthly excluded doctor without changing assignments', function (string $role): void {
    [$admin] = assignmentRoster();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    $shift = shiftOn('2026-10-01', 'weekday_day');
    $assignment = $shift->assignments()->create([
        'doctor_id' => $doctor->id,
        'role' => RosterAssignmentRole::from($role),
        'slot_number' => 1,
    ]);
    $before = RosterAssignment::query()->orderBy('id')->get()->toArray();
    DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10]);

    $this->actingAs($admin)->from(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->post(generateUrl())
        ->assertSessionHasErrors(['roster' => "{$doctor->name} ({$doctor->short_code}) already has an assignment on {$shift->shift_date->toDateString()} {$shift->shiftType->name} but is excluded for this month. Correct the assignment or regenerate the roster before generating."]);

    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and($assignment->fresh())->not->toBeNull();
})->with([
    'Main' => RosterAssignmentRole::Main->value,
    'Optional' => RosterAssignmentRole::Optional->value,
]);

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
    $requiredMain = $shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count);
    $requiredOptional = $shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->optional_count);
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
        expectGeneratedAssignmentsRoleAwareConflictFree();
    }
    if (getenv('ROSTER_BENCHMARK')) {
        fwrite(STDERR, 'ROSTER_20_RUN '.json_encode(['fastest' => min($timings), 'slowest' => max($timings), 'average' => array_sum($timings) / count($timings), 'max_vacancy_states' => $maximumStates, 'max_depth' => $maximumDepth, 'total_states' => $totalStates, 'limit_hits' => $limitHits, 'unresolved' => $unresolved]).PHP_EOL);
    }
});

it('compares monthly weekday preferences across repeatable full roster generations', function () {
    [$admin, $roster] = assignmentRoster();
    $this->actingAs($admin);
    $generator = app(RosterAssignmentGenerator::class);
    $shiftTypes = ShiftType::query()->get()->keyBy('code');
    $doctors = Doctor::query()->whereIn('short_code', ['T', 'G'])->get()->keyBy('short_code');
    $scenarios = [];

    foreach (['without_preferences', 'with_preferences'] as $scenario) {
        if ($scenario === 'with_preferences') {
            foreach ([
                ['T', 'weekday_night', 4],
                ['T', 'weekend_night', 7],
                ['G', 'weekday_evening', 4],
                ['G', 'weekday_night', 4],
            ] as [$shortCode, $shiftCode, $weekday]) {
                DoctorMonthlyWeekdayPreference::create([
                    'doctor_id' => $doctors[$shortCode]->id,
                    'year' => 2026,
                    'month' => 10,
                    'shift_type_id' => $shiftTypes[$shiftCode]->id,
                    'weekday' => $weekday,
                ]);
            }
        }

        $runs = [];
        for ($run = 0; $run < 3; $run++) {
            $startedAt = microtime(true);
            if ($run === 0 && $scenario === 'without_preferences') {
                $generator->generate($roster, $admin);
            } else {
                $generator->regenerate($roster, $admin);
            }
            $elapsedSeconds = microtime(true) - $startedAt;
            $assignments = RosterAssignment::query()
                ->with(['doctor', 'rosterShift.shiftType'])
                ->get();
            $thisaraNights = $assignments->filter(fn (RosterAssignment $assignment): bool => $assignment->role === RosterAssignmentRole::Main
                && $assignment->doctor->short_code === 'T'
                && str_contains($assignment->rosterShift->shiftType->code, 'night'));
            $gangaDuties = $assignments->filter(fn (RosterAssignment $assignment): bool => $assignment->role === RosterAssignmentRole::Main
                && $assignment->doctor->short_code === 'G'
                && in_array($assignment->rosterShift->shiftType->code, ['weekday_evening', 'weekday_night'], true));
            $nightCounts = $assignments->filter(fn (RosterAssignment $assignment): bool => $assignment->role === RosterAssignmentRole::Main
                && str_contains($assignment->rosterShift->shiftType->code, 'night'))
                ->countBy(fn (RosterAssignment $assignment): int => $assignment->doctor_id);
            $workloadMinutes = $assignments->where('role', RosterAssignmentRole::Main)
                ->groupBy('doctor_id')
                ->map(fn ($doctorAssignments): int => $doctorAssignments->sum(fn (RosterAssignment $assignment): int => $assignment->rosterShift->shiftType->duration_minutes));
            $shiftRows = $roster->shifts()->with('shiftType')->get();
            $requiredMain = $shiftRows->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count);
            $requiredOptional = $shiftRows->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->optional_count);

            $runs[] = [
                'seconds' => round($elapsedSeconds, 3),
                'thisara_nights' => $thisaraNights->count(),
                'thisara_nights_on_thursday_or_sunday' => $thisaraNights->filter(fn (RosterAssignment $assignment): bool => in_array(CarbonImmutable::parse($assignment->rosterShift->shift_date)->dayOfWeekIso, [4, 7], true))->count(),
                'ganga_evening_night_duties' => $gangaDuties->count(),
                'ganga_duties_on_thursday' => $gangaDuties->filter(fn (RosterAssignment $assignment): bool => CarbonImmutable::parse($assignment->rosterShift->shift_date)->dayOfWeekIso === 4)->count(),
                'night_count_spread' => $nightCounts->isEmpty() ? 0 : $nightCounts->max() - $nightCounts->min(),
                'workload_minutes_spread' => $workloadMinutes->isEmpty() ? 0 : $workloadMinutes->max() - $workloadMinutes->min(),
                'main_filled' => $assignments->where('role', RosterAssignmentRole::Main)->count(),
                'main_required' => $requiredMain,
                'optional_filled' => $assignments->where('role', RosterAssignmentRole::Optional)->count(),
                'optional_capacity' => $requiredOptional,
            ];

            expect($runs[array_key_last($runs)]['main_filled'])->toBe($requiredMain)
                ->and($runs[array_key_last($runs)]['optional_filled'])->toBeLessThanOrEqual($requiredOptional);
            expectGeneratedAssignmentsRoleAwareConflictFree();
        }
        $scenarios[$scenario] = $runs;
    }

    if (getenv('PREFERENCE_BENCHMARK')) {
        fwrite(STDERR, 'PREFERENCE_GENERATION_COMPARISON '.json_encode($scenarios).PHP_EOL);
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

it('assigns an Optional position to a participating doctor who requested that date off', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    }, function (): void {
        ShiftType::query()->where('code', 'weekday_day')->update(['main_count' => 0, 'optional_count' => 1]);
    });
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $shift = shiftOn('2026-10-06', 'weekday_day');
    $roster->shifts()->where('id', '!=', $shift->id)->delete();
    DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-10-06',
    ]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();

    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('doctor_id', $doctor->id)
        ->where('role', RosterAssignmentRole::Optional)->exists())->toBeTrue();
});

it('does not reject an existing Optional assignment solely for a monthly shift restriction', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    }, function (): void {
        ShiftType::query()->where('code', 'weekday_day')->update(['main_count' => 0, 'optional_count' => 1]);
    });
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $shift = shiftOn('2026-10-06', 'weekday_day');
    $roster->shifts()->where('id', '!=', $shift->id)->delete();
    $assignment = $shift->assignments()->create([
        'doctor_id' => $doctor->id,
        'role' => RosterAssignmentRole::Optional,
        'slot_number' => 1,
    ]);
    DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $shift->shift_type_id,
    ]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();

    expect($assignment->fresh()->doctor_id)->toBe($doctor->id)
        ->and($assignment->fresh()->role)->toBe(RosterAssignmentRole::Optional);
});

it('applies monthly shift restrictions to Main but not Optional candidates', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    }, function (): void {
        ShiftType::query()->where('code', 'weekday_day')->update(['main_count' => 1, 'optional_count' => 1]);
    });
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $shift = shiftOn('2026-10-06', 'weekday_day');
    $roster->shifts()->where('id', '!=', $shift->id)->delete();
    DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $shift->shift_type_id,
    ]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();

    expect($shift->assignments()->where('role', RosterAssignmentRole::Main)->exists())->toBeFalse()
        ->and($shift->assignments()->where('role', RosterAssignmentRole::Optional)->firstOrFail()->doctor_id)->toBe($doctor->id);
});

it('leaves a next-day Optional vacancy after a Main Night', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    }, function (): void {
        ShiftType::query()->where('code', 'weekday_day')->update(['main_count' => 0, 'optional_count' => 1]);
    });
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $night = shiftOn('2026-10-05', 'weekday_night');
    $nextDay = shiftOn('2026-10-06', 'weekday_day');
    $roster->shifts()->whereNotIn('id', [$night->id, $nextDay->id])->delete();
    DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => $night->shift_date,
        'shift_type_id' => $night->shift_type_id,
    ]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();

    $nightAssignment = $night->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail();
    $optionalAssignment = $nextDay->assignments()->where('role', RosterAssignmentRole::Optional)->first();
    expect($nightAssignment->doctor_id)->toBe($doctor->id)
        ->and($optionalAssignment)->toBeNull()
        ->and(app(DoctorAssignmentEligibilityService::class)->roleAwareShiftConflict(
            $night,
            $nightAssignment->role,
            $nextDay,
            RosterAssignmentRole::Optional,
        ))->toBe('previous_night_assignment');
});

it('fills a next-day Main before an Optional Night when the same doctor is eligible for both', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    }, function (): void {
        ShiftType::query()->where('code', 'weekday_night')->update(['main_count' => 0, 'optional_count' => 1]);
        ShiftType::query()->where('code', 'weekday_day')->update(['main_count' => 1, 'optional_count' => 0]);
    });
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $night = shiftOn('2026-10-05', 'weekday_night');
    $nextDay = shiftOn('2026-10-06', 'weekday_day');
    $roster->shifts()->whereNotIn('id', [$night->id, $nextDay->id])->delete();
    app(PreviousNightBoundaryService::class)->saveInitialSnapshot(2026, 9, [$doctor->id]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect()->assertSessionHasNoErrors();

    $mainAssignment = $nextDay->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail();
    expect($mainAssignment->doctor_id)->toBe($doctor->id)
        ->and($night->assignments()->where('role', RosterAssignmentRole::Optional)->exists())->toBeFalse()
        ->and(app(DoctorAssignmentEligibilityService::class)->roleAwareShiftConflict(
            $night,
            RosterAssignmentRole::Optional,
            $nextDay,
            $mainAssignment->role,
        ))->toBe('previous_night_assignment');
});

it('preserves a fixed Optional Night and leaves the next-day Main vacant during Fill Unfilled Slots', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->where('short_code', '!=', 'N')->update(['is_active' => false]);
    }, function (): void {
        ShiftType::query()->where('code', 'weekday_night')->update(['main_count' => 0, 'optional_count' => 1]);
        ShiftType::query()->where('code', 'weekday_day')->update(['main_count' => 1, 'optional_count' => 0]);
    });
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $night = shiftOn('2026-10-05', 'weekday_night');
    $nextDay = shiftOn('2026-10-06', 'weekday_day');
    $roster->shifts()->whereNotIn('id', [$night->id, $nextDay->id])->delete();
    app(PreviousNightBoundaryService::class)->saveInitialSnapshot(2026, 9, [$doctor->id]);
    $fixedNightAssignment = $night->assignments()->create([
        'doctor_id' => $doctor->id,
        'role' => RosterAssignmentRole::Optional,
        'slot_number' => 1,
    ]);

    $this->actingAs($admin)->post(generateUrl())->assertRedirect()->assertSessionHasNoErrors();

    expect($fixedNightAssignment->fresh()->doctor_id)->toBe($doctor->id)
        ->and($nextDay->assignments()->where('role', RosterAssignmentRole::Main)->exists())->toBeFalse()
        ->and(app(DoctorAssignmentEligibilityService::class)->roleAwareShiftConflict(
            $nextDay,
            RosterAssignmentRole::Main,
            $night,
            RosterAssignmentRole::Optional,
        ))->toBe('previous_night_assignment');
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
    expect($rules->shiftConflict($mondayNight, $tuesdayDay))->toBe('previous_night_assignment')
        ->and($rules->shiftConflict($tuesdayDay, $mondayNight))->toBe('previous_night_assignment')
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
        ['2026-10-05', 'weekday_night', '2026-10-06', 'weekday_day', 'previous_night_assignment'],
        ['2026-10-05', 'weekday_night', '2026-10-06', 'weekday_evening', 'previous_night_assignment'],
        ['2026-10-05', 'weekday_night', '2026-10-06', 'weekday_night', 'previous_night_assignment'],
        ['2026-10-05', 'weekday_day', '2026-10-06', 'weekday_night', null],
        ['2026-10-05', 'weekday_evening', '2026-10-06', 'weekday_night', null],
        ['2026-10-05', 'weekday_night', '2026-10-07', 'weekday_day', null],
        ['2026-10-05', 'weekday_night', '2026-10-07', 'weekday_evening', null],
        ['2026-10-05', 'weekday_night', '2026-10-07', 'weekday_night', 'night_to_night_recovery'],
        ['2026-10-05', 'weekday_night', '2026-10-08', 'weekday_night', null],
        ['2026-10-03', 'weekend_night', '2026-10-04', 'weekend_day', 'previous_night_assignment'],
        ['2026-10-03', 'weekend_night', '2026-10-04', 'weekend_night', 'previous_night_assignment'],
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

it('blocks every next-day role pairing symmetrically after a Night shift', function (): void {
    assignmentRoster();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $night = shiftOn('2026-10-05', 'weekday_night');
    $nextDay = shiftOn('2026-10-06', 'weekday_day');

    foreach (RosterAssignmentRole::cases() as $nightRole) {
        foreach (RosterAssignmentRole::cases() as $nextRole) {
            expect($rules->roleAwareShiftConflict($night, $nightRole, $nextDay, $nextRole))
                ->toBe('previous_night_assignment')
                ->and($rules->roleAwareShiftConflict($nextDay, $nextRole, $night, $nightRole))
                ->toBe('previous_night_assignment');
        }
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

it('does not treat a latest Night timestamp as complete previous-month boundary data', function () {
    assignmentRoster();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $firstDay = shiftOn('2026-10-01', 'weekday_day');
    expect($rules->conflicts($doctor, $firstDay, false, collect(), collect(), CarbonImmutable::parse('2026-09-30 20:00:00')))->toBe([])
        ->and($rules->conflicts($doctor, $firstDay, false, collect(), collect(), CarbonImmutable::parse('2026-09-27 20:00:00')))->toBe([])
        ->and($rules->conflicts($doctor, $firstDay, false, collect(), collect()))->toBe([]);
});

it('applies explicit previous-month Night identities to Main and Optional symmetrically', function () {
    assignmentRoster();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $night = CarbonImmutable::parse('2026-09-30 20:00:00');
    $boundaryDoctorIds = collect([$doctor->id => true]);
    $firstDayMain = shiftOn('2026-10-01', 'weekday_day');
    $firstDayOptional = shiftOn('2026-10-01', 'weekday_day');

    expect($rules->roleAwareConflicts($doctor, $firstDayMain, RosterAssignmentRole::Main, false, true, collect(), collect(), $night, true, false, $boundaryDoctorIds))
        ->toContain('previous_night_assignment')
        ->and($rules->roleAwareConflicts($doctor, $firstDayOptional, RosterAssignmentRole::Optional, false, true, collect(), collect(), $night, true, false, $boundaryDoctorIds))
        ->toContain('previous_night_assignment');
});

it('evaluates role-aware eligibility while keeping the legacy API on Main rules', function () {
    assignmentRoster();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $dayOff = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-01']);
    $firstDay = shiftOn('2026-10-01', 'weekday_day');
    $previousNight = CarbonImmutable::parse('2026-09-30 20:00:00');

    expect($rules->roleAwareConflicts($doctor, $firstDay, RosterAssignmentRole::Optional, false, true, collect([$dayOff]), collect(), $previousNight, true, true))->toBe([])
        ->and($rules->roleAwareConflicts($doctor, $firstDay, RosterAssignmentRole::Main, false, true, collect([$dayOff]), collect(), $previousNight, true, true))
        ->toContain('day_off_overlap', 'monthly_shift_restriction')
        ->and($rules->conflicts($doctor, $firstDay, false, collect([$dayOff]), collect(), $previousNight, true, true))
        ->toContain('day_off_overlap', 'monthly_shift_restriction');

    $night = shiftOn('2026-10-05', 'weekday_night');
    $nextDay = shiftOn('2026-10-06', 'weekday_day');
    $twoDaysLaterNight = shiftOn('2026-10-07', 'weekday_night');
    $mainNight = collect([['shift' => $night, 'role' => RosterAssignmentRole::Main]]);
    $optionalNight = collect([['shift' => $night, 'role' => RosterAssignmentRole::Optional]]);
    $optionalNightTwoDaysAgo = collect([['shift' => shiftOn('2026-10-05', 'weekday_night'), 'role' => RosterAssignmentRole::Optional]]);

    expect($rules->roleAwareConflicts($doctor, $nextDay, RosterAssignmentRole::Optional, false, true, collect(), $mainNight))->toContain('previous_night_assignment')
        ->and($rules->roleAwareConflicts($doctor, $nextDay, RosterAssignmentRole::Main, false, true, collect(), $mainNight))->toContain('previous_night_assignment')
        ->and($rules->roleAwareConflicts($doctor, $nextDay, RosterAssignmentRole::Main, false, true, collect(), $optionalNight))->toContain('previous_night_assignment')
        ->and($rules->roleAwareConflicts($doctor, $nextDay, RosterAssignmentRole::Optional, false, true, collect(), $optionalNight))->toContain('previous_night_assignment')
        ->and($rules->roleAwareConflicts($doctor, $twoDaysLaterNight, RosterAssignmentRole::Main, false, true, collect(), collect([['shift' => $night, 'role' => RosterAssignmentRole::Main]])))->toContain('night_to_night_recovery')
        ->and($rules->roleAwareConflicts($doctor, $twoDaysLaterNight, RosterAssignmentRole::Optional, false, true, collect(), $optionalNightTwoDaysAgo))->toBe([])
        ->and($rules->roleAwareConflicts($doctor, $nextDay, RosterAssignmentRole::Optional, false, true, collect(), collect([['shift' => $nextDay, 'role' => RosterAssignmentRole::Main]])))->toContain('same_start_date')
        ->and($rules->roleAwareConflicts($doctor, $nextDay, RosterAssignmentRole::Optional, false, true, collect(), collect([['shift' => $nextDay, 'role' => RosterAssignmentRole::Optional]])))->toContain('same_start_date')
        ->and($rules->roleAwareConflicts($doctor, $nextDay, RosterAssignmentRole::Main, false, true, collect(), collect([['shift' => $nextDay, 'role' => RosterAssignmentRole::Optional]])))->toContain('same_start_date');

    expect($rules->roleAwareConflicts($doctor, $firstDay, RosterAssignmentRole::Optional, true, false, collect(), collect()))
        ->toContain('monthly_exclusion', 'not_participating')
        ->and($rules->roleAwareConflicts($doctor, $firstDay, RosterAssignmentRole::Optional, false, true, collect(), collect(), null, true, true))
        ->toBe([]);

    $doctor->update(['is_active' => false]);
    expect($rules->roleAwareConflicts($doctor->fresh(), $firstDay, RosterAssignmentRole::Optional, false, true, collect(), collect()))
        ->toContain('inactive_doctor');
});

it('blocks genuine cross-date shift overlaps across assignment roles', function () {
    assignmentRoster();
    $rules = app(DoctorAssignmentEligibilityService::class);
    $night = shiftOn('2026-10-05', 'weekday_night');
    $day = shiftOn('2026-10-06', 'weekday_day');
    $overlappingDayType = clone $day->shiftType;
    $overlappingDayType->start_time = '07:30:00';
    $overlappingDay = clone $day;
    $overlappingDay->setRelation('shiftType', $overlappingDayType);

    $intervals = app(RequestIntervalService::class);
    expect($intervals->overlaps(
        $intervals->forDate($night->shift_date, $night->shiftType),
        $intervals->forDate($overlappingDay->shift_date, $overlappingDay->shiftType),
    ))->toBeTrue()
        ->and($rules->roleAwareShiftConflict($day, RosterAssignmentRole::Optional, $night, RosterAssignmentRole::Main))->toBe('previous_night_assignment')
        ->and($rules->roleAwareShiftConflict($overlappingDay, RosterAssignmentRole::Optional, $night, RosterAssignmentRole::Main))->toBe('previous_night_assignment')
        ->and($rules->roleAwareShiftConflict($overlappingDay, RosterAssignmentRole::Main, $night, RosterAssignmentRole::Optional))->toBe('previous_night_assignment')
        ->and($rules->roleAwareShiftConflict($overlappingDay, RosterAssignmentRole::Optional, $night, RosterAssignmentRole::Optional))->toBe('previous_night_assignment')
        ->and($rules->roleAwareShiftConflict($day, RosterAssignmentRole::Main, $night, RosterAssignmentRole::Main))->toBe('previous_night_assignment');
});

it('honors explicit previous-month Night identities during generation', function () {
    [$admin] = assignmentRoster();
    $doctor = Doctor::query()->where('is_active', true)->orderBy('short_code')->firstOrFail();
    DoctorMonthlyWorkload::query()->updateOrCreate(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 9], [
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 9,
        'actual_worked_minutes' => 0,
        'most_recent_night_shift_at' => '2026-09-30 20:00:00',
    ]);
    $boundaryDoctors = Doctor::query()->where('is_active', true)->orderBy('short_code')->limit(2)->get();
    app(InitialWorkloadSetupService::class)->recordNightBoundary(2026, 9, $boundaryDoctors->modelKeys());

    $this->actingAs($admin)->post(generateUrl())->assertRedirect();
    $firstDateShiftIds = RosterShift::query()->whereDate('shift_date', '2026-10-01')->pluck('id');
    expect(RosterAssignment::query()->whereIn('doctor_id', $boundaryDoctors->modelKeys())->whereIn('roster_shift_id', $firstDateShiftIds)->exists())->toBeFalse();
    $this->assertDatabaseCount('doctor_monthly_workloads', Doctor::query()->count());
});

it('requires exactly the configured number of distinct existing doctors in an initial boundary snapshot', function (): void {
    assignmentRoster();
    $doctorIds = Doctor::query()->orderBy('id')->limit(2)->pluck('id')->all();
    $boundary = app(PreviousNightBoundaryService::class);

    expect(fn () => $boundary->saveInitialSnapshot(2026, 9, []))
        ->toThrow(ValidationException::class, 'Select exactly 2 doctors');
    expect(fn () => $boundary->saveInitialSnapshot(2026, 9, [$doctorIds[0]]))
        ->toThrow(ValidationException::class, 'Select exactly 2 doctors');
    expect(fn () => $boundary->saveInitialSnapshot(2026, 9, [$doctorIds[0], $doctorIds[0]]))
        ->toThrow(ValidationException::class, 'Choose distinct existing doctors');
    expect(fn () => $boundary->saveInitialSnapshot(2026, 9, [$doctorIds[0], 999999]))
        ->toThrow(ValidationException::class, 'Choose distinct existing doctors');
});

it('accepts complete boundary doctors without requiring them to remain active', function (): void {
    assignmentRoster();
    $doctors = Doctor::query()->orderBy('id')->limit(2)->get();
    Doctor::query()->whereIn('id', $doctors->modelKeys())->update(['is_active' => false]);

    app(PreviousNightBoundaryService::class)->saveInitialSnapshot(2026, 9, $doctors->modelKeys());

    expect(app(PreviousNightBoundaryService::class)->doctorIdsForMonth(2026, 10)->keys()->sort()->values()->all())
        ->toBe($doctors->modelKeys());
});

it('requires all saved Main boundary Night positions and allows Optional vacancies', function (): void {
    [$admin] = assignmentRoster();
    $september = Roster::create([
        'year' => 2026,
        'month' => 9,
        'status' => RosterStatus::Final,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
    $nightType = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $nightShift = RosterShift::create([
        'roster_id' => $september->id,
        'shift_date' => '2026-09-30',
        'shift_type_id' => $nightType->id,
    ]);
    $nightShift->capacitySnapshot()->create(['main_count' => 2, 'optional_count' => 2]);
    $boundary = app(PreviousNightBoundaryService::class);

    expect(fn () => $boundary->doctorIdsForMonth(2026, 10))
        ->toThrow(ValidationException::class, 'must have all saved Main positions filled');

    $doctors = Doctor::query()->orderBy('id')->limit(2)->get();
    RosterAssignment::create([
        'roster_shift_id' => $nightShift->id,
        'doctor_id' => $doctors[0]->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);
    expect(fn () => $boundary->doctorIdsForMonth(2026, 10))
        ->toThrow(ValidationException::class, 'must have all saved Main positions filled');
    RosterAssignment::create([
        'roster_shift_id' => $nightShift->id,
        'doctor_id' => $doctors[1]->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 2,
    ]);

    expect($boundary->doctorIdsForMonth(2026, 10)->keys()->sort()->values()->all())
        ->toBe($doctors->modelKeys());

    $optionalDoctor = Doctor::query()->whereNotIn('id', $doctors->modelKeys())->orderBy('id')->firstOrFail();
    RosterAssignment::create([
        'roster_shift_id' => $nightShift->id,
        'doctor_id' => $optionalDoctor->id,
        'role' => RosterAssignmentRole::Optional,
        'slot_number' => 2,
    ]);

    expect($boundary->doctorIdsForMonth(2026, 10)->keys()->sort()->values()->all())
        ->toBe([...$doctors->modelKeys(), $optionalDoctor->id]);
});

it('rejects a boundary change that conflicts with a dependent Final roster without replacing the saved snapshot', function (): void {
    [, $roster] = assignmentRoster();
    $roster->update(['status' => RosterStatus::Final]);
    $doctors = Doctor::query()->orderBy('id')->limit(2)->get();
    $firstDay = shiftOn('2026-10-01', 'weekday_day');
    foreach ($doctors as $index => $doctor) {
        RosterAssignment::create([
            'roster_shift_id' => $firstDay->id,
            'doctor_id' => $doctor->id,
            'role' => RosterAssignmentRole::Main,
            'slot_number' => $index + 1,
        ]);
    }
    $boundary = app(PreviousNightBoundaryService::class);
    $originalDoctorIds = $boundary->doctorIdsForMonth(2026, 10)->keys()->sort()->values()->all();

    expect(fn () => $boundary->saveInitialSnapshot(2026, 9, $doctors->modelKeys()))
        ->toThrow(ValidationException::class, 'conflicts with the finalized October 2026 roster')
        ->and($boundary->doctorIdsForMonth(2026, 10)->keys()->sort()->values()->all())
        ->toBe($originalDoctorIds);
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

it('distributes Nights and Optionals within the month when one October doctor did not participate in September', function () {
    [$admin, $roster] = assignmentRoster(function (): void {
        Doctor::query()->whereNotIn('short_code', ['N', 'S', 'C'])->update(['is_active' => false]);
    }, function (): void {
        ShiftType::query()->where('code', 'weekday_night')->update(['main_count' => 1, 'optional_count' => 0]);
        ShiftType::query()->where('code', 'weekday_day')->update(['main_count' => 0, 'optional_count' => 1]);
    });
    $doctors = Doctor::query()->whereIn('short_code', ['N', 'S', 'C'])->get()->keyBy('short_code');
    $joiningDoctor = $doctors->get('C');
    DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->update([
        'actual_night_duty_count' => 4,
        'optional_assignment_count' => 8,
    ]);
    DoctorMonthlyWorkload::query()->where('doctor_id', $joiningDoctor->id)->where('year', 2026)->where('month', 9)->update([
        'actual_night_duty_count' => 0,
        'optional_assignment_count' => 0,
    ]);
    DoctorMonthlyParticipation::query()->where('doctor_id', $joiningDoctor->id)->where('year', 2026)->where('month', 9)
        ->update(['is_participating' => false]);

    app(PreviousNightBoundaryService::class)->saveInitialSnapshot(2026, 9, [$doctors->get('N')->id]);

    $nightShifts = collect(['2026-10-05', '2026-10-09', '2026-10-15'])
        ->map(fn (string $date): RosterShift => shiftOn($date, 'weekday_night'));
    $optionalShifts = collect(['2026-10-06', '2026-10-12', '2026-10-19'])
        ->map(fn (string $date): RosterShift => shiftOn($date, 'weekday_day'));
    $roster->shifts()->whereNotIn('id', $nightShifts->merge($optionalShifts)->pluck('id')->all())->delete();

    $this->actingAs($admin)->post(generateUrl())->assertRedirect()->assertSessionHasNoErrors();

    $assignments = RosterAssignment::query()->with(['rosterShift.shiftType'])->get();
    foreach ($doctors as $doctor) {
        expect($assignments->filter(fn (RosterAssignment $assignment): bool => $assignment->doctor_id === $doctor->id
            && $assignment->role === RosterAssignmentRole::Main
            && $assignment->rosterShift->shiftType->is_overnight)->count())->toBe(1)
            ->and($assignments->where('doctor_id', $doctor->id)->where('role', RosterAssignmentRole::Optional)->count())->toBe(1);
    }
    expect($assignments->where('role', RosterAssignmentRole::Main)->count())->toBe(3)
        ->and($assignments->where('role', RosterAssignmentRole::Optional)->count())->toBe(3);
});
