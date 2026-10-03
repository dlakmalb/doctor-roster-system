<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorMonthlyWorkloadService;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function actualFixture(): array
{
    test()->seed(ShiftTypesSeeder::class);
    $admin = User::factory()->create();
    $doctors = collect(['A', 'B', 'C'])->map(fn (string $code): Doctor => Doctor::create(['name' => "Doctor $code", 'short_code' => $code, 'is_active' => true]));
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);

    return [$admin, $doctors, $roster];
}

function actualShift(Roster $roster, string $date, string $code, Doctor $main, ?Doctor $optional = null): array
{
    $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => $date, 'shift_type_id' => ShiftType::query()->where('code', $code)->firstOrFail()->id]);
    $mainAssignment = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $main->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $optionalAssignment = $optional === null ? null : RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optional->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);

    return [$shift, $mainAssignment, $optionalAssignment];
}

function actualUrl(string $action, Roster $roster, array $extra = []): string
{
    return route("rosters.actual-work.$action", ['year' => $roster->year, 'month' => $roster->month, ...$extra]);
}

it('counts assumed Main work and planned Optional history without changing assignments', function () {
    [$admin, $doctors, $roster] = actualFixture();
    [$shift, $main, $optional] = actualShift($roster, '2026-10-30', 'weekday_night', $doctors[0], $doctors[1]);
    $original = RosterAssignment::query()->orderBy('id')->get()->toArray();

    $this->actingAs($admin)->post(actualUrl('confirm', $roster))->assertRedirect();

    $mainHistory = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->firstOrFail();
    $optionalHistory = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[1]->id)->firstOrFail();
    expect($mainHistory->actual_worked_minutes)->toBe($shift->shiftType->duration_minutes)
        ->and($mainHistory->actual_night_duty_count)->toBe(1)
        ->and($mainHistory->worked_final_weekend)->toBeTrue()
        ->and($optionalHistory->actual_worked_minutes)->toBe(0)
        ->and($optionalHistory->optional_assignment_count)->toBe(1)
        ->and($roster->fresh()->actual_work_confirmed_at)->not->toBeNull()
        ->and($mainHistory->source)->toBe(DoctorMonthlyWorkloadSource::System)
        ->and($mainHistory->roster_id)->toBe($roster->id);
    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($original);
    $this->post(actualUrl('confirm', $roster))->assertRedirect();
    $this->assertDatabaseCount('doctor_monthly_workloads', 3);
});

it('stores no actual doctor for Main absence and restores assumed work on removal', function () {
    [$admin, $doctors, $roster] = actualFixture();
    [, $main, $optional] = actualShift($roster, '2026-10-30', 'weekday_night', $doctors[0], $doctors[1]);
    $this->actingAs($admin);

    $this->put(actualUrl('save', $roster, ['assignment' => $main->id]), ['exception_type' => 'main_absent'])->assertRedirect();

    $absence = ActualWorkException::query()->where('planned_assignment_id', $main->id)->firstOrFail();
    $absentHistory = collect(app(DoctorMonthlyWorkloadService::class)->preview($roster)['rows'])->firstWhere('doctor_id', $doctors[0]->id);
    expect($absence->actual_doctor_id)->toBeNull()
        ->and($absentHistory['actual_worked_minutes'])->toBe(0)
        ->and($absentHistory['actual_night_duty_count'])->toBe(0);

    $this->delete(actualUrl('remove', $roster, ['exception' => $absence->id]))->assertRedirect();
    $restoredHistory = collect(app(DoctorMonthlyWorkloadService::class)->preview($roster)['rows'])->firstWhere('doctor_id', $doctors[0]->id);
    expect($restoredHistory['actual_worked_minutes'])->toBe(720)
        ->and($restoredHistory['actual_night_duty_count'])->toBe(1);

    $this->put(actualUrl('save', $roster, ['assignment' => $main->id]), ['exception_type' => 'replacement', 'actual_doctor_id' => $doctors[1]->id])->assertRedirect();
    expect(ActualWorkException::query()->where('planned_assignment_id', $main->id)->firstOrFail()->actual_doctor_id)->toBe($doctors[1]->id);
    $this->delete(actualUrl('remove', $roster, ['exception' => ActualWorkException::query()->where('planned_assignment_id', $main->id)->value('id')]))->assertRedirect();
    $this->put(actualUrl('save', $roster, ['assignment' => $optional->id]), ['exception_type' => 'optional_worked'])->assertRedirect();
    expect(ActualWorkException::query()->where('planned_assignment_id', $optional->id)->firstOrFail()->actual_doctor_id)->toBe($doctors[1]->id);
});

it('applies and removes Main absence, replacement, and Optional work as factual exceptions', function () {
    [$admin, $doctors, $roster] = actualFixture();
    [$shift, $main, $optional] = actualShift($roster, '2026-10-30', 'weekday_night', $doctors[0], $doctors[1]);
    $planned = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $this->actingAs($admin);
    $this->put(actualUrl('save', $roster, ['assignment' => $main->id]), ['exception_type' => 'main_absent'])->assertRedirect();
    $preview = app(DoctorMonthlyWorkloadService::class)->preview($roster)['rows'];
    $absent = collect($preview)->firstWhere('doctor_id', $doctors[0]->id);
    expect($absent['actual_worked_minutes'])->toBe(0)
        ->and($absent['actual_night_duty_count'])->toBe(0)
        ->and($absent['worked_final_weekend'])->toBeFalse();
    $this->post(actualUrl('confirm', $roster))->assertRedirect();
    $confirmedAt = $roster->fresh()->actual_work_confirmed_at;
    DoctorRequest::create(['doctor_id' => $doctors[1]->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-30']);
    $this->travel(2)->seconds();
    $this->put(actualUrl('save', $roster, ['assignment' => $main->id]), ['exception_type' => 'replacement', 'actual_doctor_id' => $doctors[1]->id])->assertRedirect();
    $replacement = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[1]->id)->firstOrFail();
    expect($replacement->actual_worked_minutes)->toBe(720)
        ->and($replacement->actual_night_duty_count)->toBe(1)
        ->and($replacement->optional_assignment_count)->toBe(1)
        ->and($replacement->worked_final_weekend)->toBeTrue()
        ->and($roster->fresh()->actual_work_confirmed_at->greaterThan($confirmedAt))->toBeTrue();
    $this->put(actualUrl('save', $roster, ['assignment' => $optional->id]), ['exception_type' => 'optional_worked'])->assertSessionHasErrors('actual_work');
    expect(ActualWorkException::query()->where('planned_assignment_id', $optional->id)->exists())->toBeFalse();
    $exception = ActualWorkException::query()->where('planned_assignment_id', $main->id)->firstOrFail();
    $this->delete(actualUrl('remove', $roster, ['exception' => $exception->id]))->assertRedirect();
    $this->put(actualUrl('save', $roster, ['assignment' => $optional->id]), ['exception_type' => 'optional_worked'])->assertRedirect();
    expect(DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->value('actual_worked_minutes'))->toBe(720)
        ->and(DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[1]->id)->value('actual_worked_minutes'))->toBe(720);
    $this->delete(actualUrl('remove', $roster, ['exception' => ActualWorkException::query()->where('planned_assignment_id', $optional->id)->value('id')]))->assertRedirect();
    expect(DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[1]->id)->value('actual_worked_minutes'))->toBe(0)
        ->and($main->fresh()->doctor_id)->toBe($doctors[0]->id)
        ->and(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($planned);
});

it('uses Friday, Saturday, and Sunday duties in the final weekend period across month boundaries', function () {
    [$admin, $doctors, $roster] = actualFixture();
    $roster->update(['month' => 11]);
    actualShift($roster, '2026-11-27', 'weekday_night', $doctors[0]);
    actualShift($roster, '2026-11-28', 'weekend_day', $doctors[1]);
    actualShift($roster, '2026-11-29', 'weekend_night', $doctors[2]);
    $this->actingAs($admin)->post(actualUrl('confirm', $roster))->assertRedirect();
    expect(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 11)->where('worked_final_weekend', true)->count())->toBe(3);

    $july = Roster::create(['year' => 2026, 'month' => 7, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    actualShift($july, '2026-07-31', 'weekday_night', $doctors[0]);
    $preview = app(DoctorMonthlyWorkloadService::class)->preview($july)['rows'];
    expect(collect($preview)->firstWhere('doctor_id', $doctors[0]->id)['worked_final_weekend'])->toBeTrue();
});

it('counts a worked Optional Night and final weekend only while the exception exists', function () {
    [$admin, $doctors, $roster] = actualFixture();
    [, , $optional] = actualShift($roster, '2026-10-30', 'weekday_night', $doctors[0], $doctors[1]);
    $this->actingAs($admin);
    $this->put(actualUrl('save', $roster, ['assignment' => $optional->id]), ['exception_type' => 'optional_worked'])->assertRedirect();
    $this->post(actualUrl('confirm', $roster))->assertRedirect();
    $history = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[1]->id)->firstOrFail();
    expect($history->actual_worked_minutes)->toBe(720)
        ->and($history->actual_night_duty_count)->toBe(1)
        ->and($history->worked_final_weekend)->toBeTrue()
        ->and($history->most_recent_night_shift_at?->format('Y-m-d H:i:s'))->toBe('2026-10-30 20:00:00')
        ->and($history->optional_assignment_count)->toBe(1);

    $exception = ActualWorkException::query()->where('planned_assignment_id', $optional->id)->firstOrFail();
    $this->delete(actualUrl('remove', $roster, ['exception' => $exception->id]))->assertRedirect();
    expect($history->fresh()->actual_worked_minutes)->toBe(0)
        ->and($history->fresh()->actual_night_duty_count)->toBe(0)
        ->and($history->fresh()->worked_final_weekend)->toBeFalse()
        ->and($history->fresh()->optional_assignment_count)->toBe(1);
});

it('rejects incompatible and duplicate actual duties without changing valid exception state', function () {
    [$admin, $doctors, $roster] = actualFixture();
    [$shift, $firstMain, $optional] = actualShift($roster, '2026-10-31', 'weekend_day', $doctors[0], $doctors[1]);
    $secondMain = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[2]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 2]);
    $this->actingAs($admin);
    $this->put(actualUrl('save', $roster, ['assignment' => $firstMain->id]), ['exception_type' => 'replacement', 'actual_doctor_id' => $doctors[1]->id])->assertRedirect();
    $this->put(actualUrl('save', $roster, ['assignment' => $secondMain->id]), ['exception_type' => 'replacement', 'actual_doctor_id' => $doctors[1]->id])->assertSessionHasErrors('actual_work');
    $this->put(actualUrl('save', $roster, ['assignment' => $optional->id]), ['exception_type' => 'optional_worked'])->assertSessionHasErrors('actual_work');
    $this->put(actualUrl('save', $roster, ['assignment' => $firstMain->id]), ['exception_type' => 'replacement', 'actual_doctor_id' => $doctors[2]->id])->assertSessionHasErrors('actual_work');
    $this->put(actualUrl('save', $roster, ['assignment' => $firstMain->id]), ['exception_type' => 'replacement', 'actual_doctor_id' => $doctors[0]->id])->assertSessionHasErrors('actual_doctor_id');
    $this->put(actualUrl('save', $roster, ['assignment' => $optional->id]), ['exception_type' => 'main_absent'])->assertSessionHasErrors('exception_type');
    $this->put(actualUrl('save', $roster, ['assignment' => $optional->id]), ['exception_type' => 'replacement', 'actual_doctor_id' => $doctors[2]->id])->assertSessionHasErrors('exception_type');
    $this->put(actualUrl('save', $roster, ['assignment' => $firstMain->id]), ['exception_type' => 'optional_worked'])->assertSessionHasErrors('exception_type');
    $this->assertDatabaseCount('actual_work_exceptions', 1);
    expect(ActualWorkException::query()->firstOrFail()->actual_doctor_id)->toBe($doctors[1]->id);
});

it('calculates each standard shift duration and the latest actual Night', function () {
    [$admin, $doctors, $roster] = actualFixture();
    foreach ([
        ['2026-10-01', 'weekday_day'],
        ['2026-10-01', 'weekday_evening'],
        ['2026-10-01', 'weekday_night'],
        ['2026-10-03', 'weekend_day'],
        ['2026-10-03', 'weekend_night'],
    ] as [$date, $code]) {
        actualShift($roster, $date, $code, $doctors[0]);
    }

    $this->actingAs($admin)->post(actualUrl('confirm', $roster))->assertRedirect();

    $history = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->firstOrFail();
    expect($history->actual_worked_minutes)->toBe(2880)
        ->and($history->actual_night_duty_count)->toBe(2)
        ->and($history->most_recent_night_shift_at?->format('Y-m-d H:i:s'))->toBe('2026-10-03 16:00:00');
});

it('excludes monthly exclusions from the average and freezes their balance', function () {
    [$admin, $doctors, $roster] = actualFixture();
    actualShift($roster, '2026-10-01', 'weekday_day', $doctors[0]);
    actualShift($roster, '2026-10-02', 'weekday_day', $doctors[2]);
    DoctorRequest::create(['doctor_id' => $doctors[1]->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-01']);
    DoctorMonthlyExclusion::create(['doctor_id' => $doctors[2]->id, 'year' => 2026, 'month' => 10]);
    foreach ($doctors as $doctor) {
        DoctorMonthlyWorkload::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 9, 'source' => DoctorMonthlyWorkloadSource::ManualInitial, 'actual_worked_minutes' => 0, 'closing_balance_minutes' => 60]);
    }

    $this->actingAs($admin)->post(actualUrl('confirm', $roster))->assertRedirect();

    $rows = DoctorMonthlyWorkload::query()->where('month', 10)->get()->keyBy('doctor_id');
    expect($rows[$doctors[0]->id]->monthly_adjustment_minutes)->toBe(180)
        ->and($rows[$doctors[0]->id]->closing_balance_minutes)->toBe(240)
        ->and($rows[$doctors[1]->id]->monthly_adjustment_minutes)->toBe(-180)
        ->and($rows[$doctors[1]->id]->closing_balance_minutes)->toBe(-120)
        ->and($rows[$doctors[2]->id]->actual_worked_minutes)->toBe(360)
        ->and($rows[$doctors[2]->id]->closing_balance_minutes)->toBe(60);
});

it('rounds the included group average to the nearest minute and handles all excluded doctors', function () {
    $service = app(DoctorMonthlyWorkloadService::class);
    $result = $service->balances([
        ['doctor_id' => 1, 'actual_worked_minutes' => 1, 'opening_balance_minutes' => 2, 'is_month_excluded' => false],
        ['doctor_id' => 2, 'actual_worked_minutes' => 0, 'opening_balance_minutes' => -2, 'is_month_excluded' => false],
    ]);
    expect($result['average'])->toBe(1)
        ->and($result['rows'][0]['closing_balance_minutes'])->toBe(2)
        ->and($result['rows'][1]['closing_balance_minutes'])->toBe(-3);
    $excluded = $service->balances([
        ['doctor_id' => 1, 'actual_worked_minutes' => 600, 'opening_balance_minutes' => 90, 'is_month_excluded' => true],
    ]);
    expect($excluded['average'])->toBe(0)
        ->and($excluded['rows'][0]['monthly_adjustment_minutes'])->toBe(0)
        ->and($excluded['rows'][0]['closing_balance_minutes'])->toBe(90);
});

it('refuses Draft confirmation and cross-roster assignment mutations', function () {
    [$admin, $doctors, $roster] = actualFixture();
    $other = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    [, $otherAssignment] = actualShift($other, '2026-11-02', 'weekday_day', $doctors[0]);
    $this->actingAs($admin)->put(actualUrl('save', $roster, ['assignment' => $otherAssignment->id]), ['exception_type' => 'main_absent'])->assertNotFound();
    $roster->update(['status' => RosterStatus::Draft]);
    $this->post(actualUrl('confirm', $roster))->assertSessionHasErrors('roster');
    $this->assertDatabaseCount('doctor_monthly_workloads', 0);
});

it('rolls back confirmation and a confirmed correction when workload persistence fails', function () {
    [$admin, $doctors, $roster] = actualFixture();
    [, $assignment] = actualShift($roster, '2026-10-01', 'weekday_day', $doctors[0]);
    $this->actingAs($admin);
    $this->withoutExceptionHandling();
    DoctorMonthlyWorkload::saving(function (): void {
        throw new RuntimeException('Simulated workload failure');
    });
    try {
        $this->post(actualUrl('confirm', $roster));
        test()->fail('Confirmation should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated workload failure');
    } finally {
        DoctorMonthlyWorkload::flushEventListeners();
    }
    expect($roster->fresh()->actual_work_confirmed_at)->toBeNull();
    $this->assertDatabaseCount('doctor_monthly_workloads', 0);

    $this->post(actualUrl('confirm', $roster))->assertRedirect();
    DoctorMonthlyWorkload::saving(function (): void {
        throw new RuntimeException('Simulated correction failure');
    });
    try {
        $this->put(actualUrl('save', $roster, ['assignment' => $assignment->id]), ['exception_type' => 'main_absent']);
        test()->fail('Correction should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated correction failure');
    } finally {
        DoctorMonthlyWorkload::flushEventListeners();
    }
    $this->assertDatabaseCount('actual_work_exceptions', 0);
    expect(DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->value('actual_worked_minutes'))->toBe(360);
});

it('requires authentication for actual review and baseline routes', function () {
    [, , $roster] = actualFixture();
    $this->get(actualUrl('show', $roster))->assertRedirect(route('login'));
    $this->post(actualUrl('confirm', $roster))->assertRedirect(route('login'));
    $this->put(actualUrl('save', $roster, ['assignment' => 1]), ['exception_type' => 'main_absent'])->assertRedirect(route('login'));
    $this->delete(actualUrl('remove', $roster, ['exception' => 1]))->assertRedirect(route('login'));
    $this->get(route('initial-workload.show', ['year' => 2026, 'month' => 9]))->assertRedirect(route('login'));
    $this->post(route('initial-workload.save', ['year' => 2026, 'month' => 9]))->assertRedirect(route('login'));
});

it('exposes review data and calculated history to the Inertia page', function () {
    [$admin, $doctors, $roster] = actualFixture();
    actualShift($roster, '2026-10-01', 'weekday_day', $doctors[0], $doctors[1]);

    $this->actingAs($admin)->get(actualUrl('show', $roster))->assertInertia(fn (Assert $page) => $page
        ->component('actual-work-review')->where('status', 'final')->where('confirmed_at', null)
        ->where('shifts.0.assignments.0.doctor.short_code', 'A')->where('shifts.0.assignments.1.role', 'optional')
        ->where('preview.rows.0.actual_worked_minutes', 360)->where('summary.replacements', 0));
});
