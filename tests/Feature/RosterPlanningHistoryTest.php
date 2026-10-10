<?php

use App\Enums\ActualWorkExceptionType;
use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorMonthlyParticipationService;
use App\Services\RosterDraftValidationService;
use App\Services\RosterHistoryReadinessService;
use App\Services\RosterPlanningHistoryService;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Validation\ValidationException;

function planningFixture(): array
{
    test()->seed(ShiftTypesSeeder::class);
    $admin = User::factory()->create();
    $doctors = collect(['A', 'B', 'C'])->map(fn (string $code): Doctor => Doctor::create(['name' => "Doctor $code", 'short_code' => $code, 'is_active' => true]));
    foreach ($doctors as $doctor) {
        DoctorMonthlyWorkload::create([
            'doctor_id' => $doctor->id,
            'year' => 2026,
            'month' => 9,
            'source' => DoctorMonthlyWorkloadSource::ManualInitial,
            'actual_worked_minutes' => 0,
            'closing_balance_minutes' => $doctor->id === $doctors[0]->id ? 60 : 0,
        ]);
        app(DoctorMonthlyParticipationService::class)->saveBaseline($doctor->id, 2026, 9, true);
    }
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $admin->id, 'finalized_at' => now()]);
    snapshotRosterParticipation($october, $doctors);
    $nightType = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $shift = RosterShift::create(['roster_id' => $october->id, 'shift_date' => '2026-10-30', 'shift_type_id' => $nightType->id]);
    $main = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $excludedShift = RosterShift::create(['roster_id' => $october->id, 'shift_date' => '2026-10-31', 'shift_type_id' => ShiftType::query()->where('code', 'weekend_day')->firstOrFail()->id]);
    RosterAssignment::create(['roster_shift_id' => $excludedShift->id, 'doctor_id' => $doctors[2]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    RosterAssignment::create(['roster_shift_id' => $excludedShift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    DoctorMonthlyExclusion::create(['doctor_id' => $doctors[2]->id, 'year' => 2026, 'month' => 10]);
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);

    return [$admin, $doctors, $october, $november, $main, $nightType];
}

it('applies unconfirmed Main Absence and Optional Worked history in memory', function () {
    [$admin, $doctors, $october, , $main] = planningFixture();
    ActualWorkException::create(['roster_shift_id' => $main->roster_shift_id, 'planned_assignment_id' => $main->id, 'exception_type' => ActualWorkExceptionType::MainAbsent, 'recorded_by' => $admin->id]);
    $optionalAssignment = RosterAssignment::query()
        ->where('doctor_id', $doctors[1]->id)
        ->where('role', RosterAssignmentRole::Optional)
        ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->firstOrFail();
    ActualWorkException::create([
        'roster_shift_id' => $optionalAssignment->roster_shift_id,
        'planned_assignment_id' => $optionalAssignment->id,
        'exception_type' => ActualWorkExceptionType::OptionalWorked,
        'actual_doctor_id' => $doctors[1]->id,
        'recorded_by' => $admin->id,
    ]);

    $readiness = app(RosterHistoryReadinessService::class)->forMonth(2026, 11);
    $history = app(RosterPlanningHistoryService::class)->forMonth(2026, 11);
    $first = $history->get($doctors[0]->id);
    $optional = $history->get($doctors[1]->id);
    $excluded = $history->get($doctors[2]->id);

    expect($readiness['ready'])->toBeTrue()
        ->and($readiness['basis'])->toBe('final_planned')
        ->and($first->actual_worked_minutes)->toBe(0)
        ->and($first->actual_night_duty_count)->toBe(0)
        ->and($first->worked_final_weekend)->toBeFalse()
        ->and($first->most_recent_night_shift_at)->toBeNull()
        ->and($first->opening_balance_minutes)->toBe(60)
        ->and($first->monthly_adjustment_minutes)->toBe(-intdiv($optionalAssignment->rosterShift->shiftType->duration_minutes + 1, 2))
        ->and($first->closing_balance_minutes)->toBe(60 - intdiv($optionalAssignment->rosterShift->shiftType->duration_minutes + 1, 2))
        ->and($optional->actual_worked_minutes)->toBe($optionalAssignment->rosterShift->shiftType->duration_minutes)
        ->and($optional->optional_assignment_count)->toBe(1)
        ->and($optional->actual_night_duty_count)->toBe(0)
        ->and($optional->worked_final_weekend)->toBeTrue()
        ->and($optional->most_recent_night_shift_at)->toBeNull()
        ->and($excluded->actual_worked_minutes)->toBeGreaterThan(0)
        ->and($excluded->monthly_adjustment_minutes)->toBe(0)
        ->and($excluded->closing_balance_minutes)->toBe($excluded->opening_balance_minutes)
        ->and($first->exists)->toBeFalse();
    $this->assertDatabaseCount('doctor_monthly_workloads', 3);
});

it('credits an unconfirmed Main Replacement and Optional Worked assignment', function () {
    [$admin, $doctors, $october, , $main] = planningFixture();
    ActualWorkException::create([
        'roster_shift_id' => $main->roster_shift_id,
        'planned_assignment_id' => $main->id,
        'exception_type' => ActualWorkExceptionType::Replacement,
        'actual_doctor_id' => $doctors[1]->id,
        'recorded_by' => $admin->id,
    ]);
    $optionalAssignment = RosterAssignment::query()
        ->where('doctor_id', $doctors[1]->id)
        ->where('role', RosterAssignmentRole::Optional)
        ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->firstOrFail();
    ActualWorkException::create([
        'roster_shift_id' => $optionalAssignment->roster_shift_id,
        'planned_assignment_id' => $optionalAssignment->id,
        'exception_type' => ActualWorkExceptionType::OptionalWorked,
        'actual_doctor_id' => $doctors[1]->id,
        'recorded_by' => $admin->id,
    ]);

    $history = app(RosterPlanningHistoryService::class)->forMonth(2026, 11);
    $mainDoctor = $history->get($doctors[0]->id);
    $replacement = $history->get($doctors[1]->id);

    expect($mainDoctor->actual_worked_minutes)->toBe(0)
        ->and($mainDoctor->actual_night_duty_count)->toBe(0)
        ->and($replacement->actual_worked_minutes)->toBe($main->rosterShift->shiftType->duration_minutes + $optionalAssignment->rosterShift->shiftType->duration_minutes)
        ->and($replacement->actual_night_duty_count)->toBe(1)
        ->and($replacement->most_recent_night_shift_at?->format('Y-m-d'))->toBe('2026-10-30')
        ->and($replacement->worked_final_weekend)->toBeTrue()
        ->and($replacement->optional_assignment_count)->toBe(1);
});

it('generates from planned history without persisting it and switches to confirmed actual history', function () {
    [$admin, $doctors, $october, $november, $main] = planningFixture();
    $novemberShift = RosterShift::create(['roster_id' => $november->id, 'shift_date' => '2026-11-02', 'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->firstOrFail()->id]);
    RosterAssignment::create(['roster_shift_id' => $novemberShift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 11]))->assertRedirect();
    expect($november->fresh()->last_generated_at)->not->toBeNull();
    $this->assertDatabaseCount('doctor_monthly_workloads', 3);
    $generatedAt = $november->fresh()->last_generated_at;
    $assignmentIds = $novemberShift->assignments()->pluck('id')->all();
    $this->travel(2)->seconds();
    $this->put(route('rosters.actual-work.save', ['year' => 2026, 'month' => 10, 'assignment' => $main->id]), ['exception_type' => 'main_absent'])->assertRedirect();
    $provisionalHistory = app(RosterPlanningHistoryService::class)->forMonth(2026, 11)->get($doctors[0]->id);
    expect($provisionalHistory->actual_worked_minutes)->toBe(0)
        ->and($provisionalHistory->actual_night_duty_count)->toBe(0)
        ->and($provisionalHistory->worked_final_weekend)->toBeFalse()
        ->and($provisionalHistory->most_recent_night_shift_at)->toBeNull();
    $this->post(route('rosters.actual-work.confirm', ['year' => 2026, 'month' => 10]))->assertRedirect();
    expect(app(RosterPlanningHistoryService::class)->forMonth(2026, 11)->get($doctors[0]->id)->actual_worked_minutes)->toBe(0)
        ->and(app(RosterHistoryReadinessService::class)->forMonth(2026, 11)['basis'])->toBe('confirmed_actual')
        ->and($november->fresh()->last_generated_at?->equalTo($generatedAt))->toBeTrue()
        ->and($novemberShift->assignments()->pluck('id')->all())->toBe($assignmentIds)
        ->and(collect(app(RosterDraftValidationService::class)->validate($november->fresh()))->contains(fn (array $item): bool => str_contains($item['message'], 'history changed')))->toBeTrue();
    $this->travel(2)->seconds();
    $this->post(route('rosters.regenerate', ['year' => 2026, 'month' => 11]))->assertRedirect();
    expect(collect(app(RosterDraftValidationService::class)->validate($november->fresh()))->contains(fn (array $item): bool => str_contains($item['message'], 'history changed')))->toBeFalse();
    $november->update(['status' => RosterStatus::Final]);
    $regeneratedAt = $november->fresh()->last_generated_at;
    $this->travel(2)->seconds();
    $exception = ActualWorkException::query()->where('planned_assignment_id', $main->id)->firstOrFail();
    $this->delete(route('rosters.actual-work.remove', ['year' => 2026, 'month' => 10, 'exception' => $exception->id]))->assertRedirect();
    expect($november->fresh()->status)->toBe(RosterStatus::Final)
        ->and($november->fresh()->last_generated_at?->equalTo($regeneratedAt))->toBeTrue()
        ->and(collect(app(RosterDraftValidationService::class)->validate($november->fresh()))->contains(fn (array $item): bool => $item['code'] === 'stale_history'))->toBeFalse();
});

it('does not show an actionable stale-history warning on a Final next roster', function () {
    [$admin, $doctors, $october, $november, $main] = planningFixture();
    $november->update([
        'status' => RosterStatus::Final,
        'last_generated_at' => now(),
        'generated_history_fingerprint' => app(RosterPlanningHistoryService::class)->fingerprint(2026, 11),
    ]);
    $generatedAt = $november->fresh()->last_generated_at;
    $this->travel(2)->seconds();
    $october->update(['status' => RosterStatus::Draft, 'reopened_at' => now()]);
    $main->update(['doctor_id' => $doctors[1]->id]);
    $this->travel(2)->seconds();
    $october->update(['status' => RosterStatus::Final, 'finalized_at' => now()]);

    expect($november->fresh()->status)->toBe(RosterStatus::Final)
        ->and($november->fresh()->last_generated_at?->equalTo($generatedAt))->toBeTrue()
        ->and(collect(app(RosterDraftValidationService::class)->validate($november->fresh()))->contains(fn (array $item): bool => $item['code'] === 'stale_history'))->toBeFalse();
});

it('rejects participation records for doctors beyond the roster snapshot boundary', function () {
    [, , $october] = planningFixture();
    $laterDoctor = Doctor::create(['name' => 'Doctor Later', 'short_code' => 'L', 'is_active' => true]);
    DoctorMonthlyParticipation::create([
        'doctor_id' => $laterDoctor->id,
        'roster_id' => $october->id,
        'year' => $october->year,
        'month' => $october->month,
        'is_participating' => false,
    ]);

    expect(fn () => app(DoctorMonthlyParticipationService::class)->doctorIdsForRoster($october))
        ->toThrow(ValidationException::class, 'incomplete or mismatched participation history');
});
