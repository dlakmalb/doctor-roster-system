<?php

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\ActualWorkReviewService;
use App\Services\RosterDraftValidationService;
use Carbon\CarbonImmutable;
use Database\Seeders\ShiftTypesSeeder;

function confirmedExclusionFixture(): array
{
    test()->seed(ShiftTypesSeeder::class);
    test()->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    $admin = User::factory()->create();
    $doctors = collect(['A', 'B', 'C'])->map(fn (string $code): Doctor => Doctor::create(['name' => "Doctor $code", 'short_code' => $code, 'is_active' => true]));
    seedInitialHistoryForGeneration();
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-01', 'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->firstOrFail()->id]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    return [$admin, $doctors, $roster];
}

function exclusionHistoryUrl(string $name, array $extra = []): string
{
    return route($name, ['year' => 2026, 'month' => 10, ...$extra]);
}

function changeConfirmedExclusion(int $year, int $month, User $admin, Closure $change): void
{
    app(ActualWorkReviewService::class)->changeMonthlyExclusion($year, $month, $admin, $change);
}

it('recalculates confirmed and later balances through the historical exclusion service', function () {
    [$admin, $doctors, $october] = confirmedExclusionFixture();
    $this->actingAs($admin)->post(exclusionHistoryUrl('rosters.actual-work.confirm'))->assertRedirect();
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    $this->post(route('rosters.actual-work.confirm', ['year' => 2026, 'month' => 11]))->assertRedirect();
    $december = Roster::create(['year' => 2026, 'month' => 12, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    RosterShift::create(['roster_id' => $december->id, 'shift_date' => '2026-12-01', 'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->firstOrFail()->id]);
    $this->post(route('rosters.generate', ['year' => 2026, 'month' => 12]))->assertRedirect();
    $plannedAssignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $december->id))->orderBy('id')->get()->toArray();
    $generatedAt = $december->fresh()->last_generated_at;
    $confirmedAt = $october->fresh()->actual_work_confirmed_at;
    $newAdmin = User::factory()->create();
    $this->actingAs($newAdmin);
    $this->travel(2)->seconds();

    changeConfirmedExclusion(2026, 10, $newAdmin, function () use ($doctors): void {
        DoctorMonthlyExclusion::create(['doctor_id' => $doctors[0]->id, 'year' => 2026, 'month' => 10]);
    });

    $excluded = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->where('month', 10)->firstOrFail();
    $included = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[1]->id)->where('month', 10)->firstOrFail();
    $later = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->where('month', 11)->firstOrFail();
    expect($excluded->is_month_excluded)->toBeTrue()
        ->and($excluded->actual_worked_minutes)->toBe(360)
        ->and($excluded->opening_balance_minutes)->toBe(0)
        ->and($excluded->monthly_adjustment_minutes)->toBe(0)
        ->and($excluded->closing_balance_minutes)->toBe(0)
        ->and($included->monthly_adjustment_minutes)->toBe(0)
        ->and($included->closing_balance_minutes)->toBe(0)
        ->and($later->opening_balance_minutes)->toBe(0)
        ->and($later->closing_balance_minutes)->toBe(0)
        ->and($october->fresh()->actual_work_confirmed_at->greaterThan($confirmedAt))->toBeTrue()
        ->and($october->fresh()->actual_work_confirmed_by)->toBe($newAdmin->id)
        ->and($december->fresh()->last_generated_at?->equalTo($generatedAt))->toBeTrue()
        ->and(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $december->id))->orderBy('id')->get()->toArray())->toBe($plannedAssignments);
    $warnings = app(RosterDraftValidationService::class)->validate($december->fresh());
    expect(collect($warnings)->contains(fn (array $warning): bool => $warning['severity'] === 'Warning' && str_contains($warning['message'], 'history changed')))->toBeTrue();

    $addedAt = $october->fresh()->actual_work_confirmed_at;
    $this->travel(2)->seconds();
    $exclusion = DoctorMonthlyExclusion::query()->where('doctor_id', $doctors[0]->id)->firstOrFail();
    changeConfirmedExclusion(2026, 10, $newAdmin, function () use ($exclusion): void {
        $exclusion->delete();
    });

    expect($excluded->fresh()->is_month_excluded)->toBeFalse()
        ->and($excluded->fresh()->monthly_adjustment_minutes)->toBe(240)
        ->and($excluded->fresh()->closing_balance_minutes)->toBe(240)
        ->and($included->fresh()->monthly_adjustment_minutes)->toBe(-120)
        ->and($included->fresh()->closing_balance_minutes)->toBe(-120)
        ->and($later->fresh()->opening_balance_minutes)->toBe(240)
        ->and($later->fresh()->closing_balance_minutes)->toBe(240)
        ->and($october->fresh()->actual_work_confirmed_by)->toBe($newAdmin->id)
        ->and($october->fresh()->actual_work_confirmed_at->greaterThan($addedAt))->toBeTrue()
        ->and(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $december->id))->orderBy('id')->get()->toArray())->toBe($plannedAssignments);
});

it('leaves workload history untouched when the historical exclusion service has no confirmed Final roster', function () {
    [$admin, $doctors, $roster] = confirmedExclusionFixture();

    changeConfirmedExclusion(2026, 10, $admin, function () use ($doctors): void {
        DoctorMonthlyExclusion::create(['doctor_id' => $doctors[0]->id, 'year' => 2026, 'month' => 10]);
    });

    expect($roster->fresh()->actual_work_confirmed_at)->toBeNull();
    $this->assertDatabaseCount('doctor_monthly_workloads', $doctors->count());
});

it('warns when an exclusion is added after generating from an unconfirmed Final plan', function () {
    [$admin, $doctors, $october] = confirmedExclusionFixture();
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    RosterShift::create(['roster_id' => $november->id, 'shift_date' => '2026-11-02', 'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->firstOrFail()->id]);
    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 11]))->assertRedirect();
    $assignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $november->id))->orderBy('id')->get()->toArray();
    $generatedAt = $november->fresh()->last_generated_at;
    expect($assignments)->not->toBeEmpty();
    $this->travel(2)->seconds();

    changeConfirmedExclusion(2026, 10, $admin, function () use ($doctors): void {
        DoctorMonthlyExclusion::create(['doctor_id' => $doctors[1]->id, 'year' => 2026, 'month' => 10]);
    });

    expect($october->fresh()->actual_work_confirmed_at)->toBeNull()
        ->and($november->fresh()->last_generated_at?->equalTo($generatedAt))->toBeTrue()
        ->and(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $november->id))->orderBy('id')->get()->toArray())->toBe($assignments)
        ->and(collect(app(RosterDraftValidationService::class)->validate($november->fresh()))->contains(fn (array $warning): bool => $warning['severity'] === 'Warning' && str_contains($warning['message'], 'history changed')))->toBeTrue();
    $this->assertDatabaseCount('doctor_monthly_workloads', $doctors->count());
});

it('warns when an exclusion is removed after generating from an unconfirmed Final plan', function () {
    [$admin, $doctors, $october] = confirmedExclusionFixture();
    changeConfirmedExclusion(2026, 10, $admin, function () use ($doctors): void {
        DoctorMonthlyExclusion::create(['doctor_id' => $doctors[1]->id, 'year' => 2026, 'month' => 10]);
    });
    $exclusion = DoctorMonthlyExclusion::query()->where('doctor_id', $doctors[1]->id)->firstOrFail();
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    RosterShift::create(['roster_id' => $november->id, 'shift_date' => '2026-11-02', 'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->firstOrFail()->id]);
    $this->actingAs($admin);
    $this->post(route('rosters.generate', ['year' => 2026, 'month' => 11]))->assertRedirect();
    $assignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $november->id))->orderBy('id')->get()->toArray();
    $generatedAt = $november->fresh()->last_generated_at;
    expect($assignments)->not->toBeEmpty();
    $this->travel(2)->seconds();

    changeConfirmedExclusion(2026, 10, $admin, function () use ($exclusion): void {
        $exclusion->delete();
    });

    expect($october->fresh()->actual_work_confirmed_at)->toBeNull()
        ->and($november->fresh()->last_generated_at?->equalTo($generatedAt))->toBeTrue()
        ->and(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $november->id))->orderBy('id')->get()->toArray())->toBe($assignments)
        ->and(collect(app(RosterDraftValidationService::class)->validate($november->fresh()))->contains(fn (array $warning): bool => $warning['severity'] === 'Warning' && str_contains($warning['message'], 'history changed')))->toBeTrue();
    $this->assertDatabaseCount('doctor_monthly_workloads', $doctors->count());
});

it('rolls back an exclusion and its confirmed history when workload persistence fails', function () {
    [$admin, $doctors, $roster] = confirmedExclusionFixture();
    $this->actingAs($admin)->post(exclusionHistoryUrl('rosters.actual-work.confirm'))->assertRedirect();
    $before = DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 10)->orderBy('doctor_id')->get()->toArray();
    $confirmedAt = $roster->fresh()->actual_work_confirmed_at;
    DoctorMonthlyWorkload::saving(function (): void {
        throw new RuntimeException('Simulated workload failure');
    });

    try {
        changeConfirmedExclusion(2026, 10, $admin, function () use ($doctors): void {
            DoctorMonthlyExclusion::create(['doctor_id' => $doctors[0]->id, 'year' => 2026, 'month' => 10]);
        });
        test()->fail('The exclusion correction should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated workload failure');
    } finally {
        DoctorMonthlyWorkload::flushEventListeners();
    }

    $this->assertDatabaseCount('doctor_monthly_exclusions', 0);
    expect(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 10)->orderBy('doctor_id')->get()->toArray())->toBe($before)
        ->and($roster->fresh()->actual_work_confirmed_at?->equalTo($confirmedAt))->toBeTrue();
});
