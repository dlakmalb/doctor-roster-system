<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RosterDraftValidationService;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function historyFixture(): array
{
    test()->seed(ShiftTypesSeeder::class);
    $admin = User::factory()->create();
    $doctors = collect(['A', 'B'])->map(fn (string $code): Doctor => Doctor::create(['name' => "Doctor $code", 'short_code' => $code, 'is_active' => true]));

    return [$admin, $doctors];
}

function baselinePayload($doctors, string $firstHours = '6'): array
{
    return ['doctors' => $doctors->values()->map(fn (Doctor $doctor, int $index): array => [
        'doctor_id' => $doctor->id,
        'actual_hours' => $index === 0 ? $firstHours : '0',
        'actual_night_duty_count' => $index === 0 ? 1 : 0,
        'optional_assignment_count' => $index === 0 ? 2 : 0,
        'worked_final_weekend' => $index === 0,
        'most_recent_night_shift_at' => $index === 0 ? '2026-09-30 20:00:00' : null,
    ])->all()];
}

it('saves and corrects only the initial baseline with zero opening balances', function () {
    [$admin, $doctors] = historyFixture();
    $url = route('initial-workload.save', ['year' => 2026, 'month' => 9]);
    $this->actingAs($admin)->post($url, baselinePayload($doctors))->assertRedirect();
    $rows = DoctorMonthlyWorkload::query()->where('month', 9)->orderBy('doctor_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->source)->toBe(DoctorMonthlyWorkloadSource::ManualInitial)
        ->and($rows[0]->roster_id)->toBeNull()
        ->and($rows[0]->opening_balance_minutes)->toBe(0)
        ->and($rows[0]->closing_balance_minutes)->toBe(180)
        ->and($rows[1]->closing_balance_minutes)->toBe(-180)
        ->and($rows[0]->actual_night_duty_count)->toBe(1)
        ->and($rows[0]->optional_assignment_count)->toBe(2)
        ->and($rows[0]->worked_final_weekend)->toBeTrue()
        ->and($rows[0]->most_recent_night_shift_at?->format('Y-m-d H:i:s'))->toBe('2026-09-30 20:00:00');
    $this->post($url, baselinePayload($doctors, '12'))->assertRedirect();
    expect($rows[0]->fresh()->closing_balance_minutes)->toBe(360);
    $this->post(route('initial-workload.save', ['year' => 2026, 'month' => 10]), baselinePayload($doctors))->assertSessionHasErrors('baseline');
    $this->get(route('initial-workload.show', ['year' => 2026, 'month' => 9]))->assertInertia(fn (Assert $page) => $page
        ->component('initial-workload-setup')->where('doctors.0.existing.actual_worked_minutes', 720));
});

it('requires baseline before first generation and a Final planned roster before later generation', function () {
    [$admin, $doctors] = historyFixture();
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    $generateOctober = route('rosters.generate', ['year' => 2026, 'month' => 10]);
    $this->actingAs($admin)->post($generateOctober)->assertSessionHasErrors('roster');
    $this->post(route('initial-workload.save', ['year' => 2026, 'month' => 9]), baselinePayload($doctors))->assertRedirect();
    $this->post($generateOctober)->assertRedirect();
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    $generateNovember = route('rosters.generate', ['year' => 2026, 'month' => 11]);
    $this->post($generateNovember)->assertSessionHasErrors(['roster' => 'Finalize October 2026 before generating November 2026.']);
    $this->post(route('rosters.regenerate', ['year' => 2026, 'month' => 11]))->assertSessionHasErrors('roster');
    $october->update(['status' => RosterStatus::Final, 'finalized_at' => now()]);
    $this->post($generateNovember)->assertRedirect();
    expect($november->fresh()->last_generated_at)->not->toBeNull()
        ->and($october->fresh()->actual_work_confirmed_at)->toBeNull();
});

it('recalculates later balances after a confirmed earlier correction without changing planned assignments', function () {
    [$admin, $doctors] = historyFixture();
    $this->actingAs($admin)->post(route('initial-workload.save', ['year' => 2026, 'month' => 9]), baselinePayload($doctors, '0'))->assertRedirect();
    $day = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    $shift = RosterShift::create(['roster_id' => $october->id, 'shift_date' => '2026-10-01', 'shift_type_id' => $day->id]);
    $assignment = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->post(route('rosters.actual-work.confirm', ['year' => 2026, 'month' => 10]))->assertRedirect();
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    $this->post(route('rosters.actual-work.confirm', ['year' => 2026, 'month' => 11]))->assertRedirect();
    $before = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->where('month', 11)->firstOrFail();
    expect($before->opening_balance_minutes)->toBe(180);

    $this->put(route('rosters.actual-work.save', ['year' => 2026, 'month' => 10, 'assignment' => $assignment->id]), ['exception_type' => 'main_absent'])->assertRedirect();

    $after = $before->fresh();
    expect($after->opening_balance_minutes)->toBe(0)
        ->and($after->actual_worked_minutes)->toBe(0)
        ->and($after->closing_balance_minutes)->toBe(0)
        ->and($assignment->fresh()->doctor_id)->toBe($doctors[0]->id);
});

it('carries an initial baseline correction through confirmed later history', function () {
    [$admin, $doctors] = historyFixture();
    $baselineUrl = route('initial-workload.save', ['year' => 2026, 'month' => 9]);
    $this->actingAs($admin)->post($baselineUrl, baselinePayload($doctors, '0'))->assertRedirect();
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    $shift = RosterShift::create(['roster_id' => $october->id, 'shift_date' => '2026-10-01', 'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->firstOrFail()->id]);
    $assignment = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->post(route('rosters.actual-work.confirm', ['year' => 2026, 'month' => 10]))->assertRedirect();
    $history = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)->where('month', 10)->firstOrFail();
    expect($history->opening_balance_minutes)->toBe(0)
        ->and($history->closing_balance_minutes)->toBe(180);

    $this->post($baselineUrl, baselinePayload($doctors, '6'))->assertRedirect();

    expect($history->fresh()->opening_balance_minutes)->toBe(180)
        ->and($history->fresh()->actual_worked_minutes)->toBe(360)
        ->and($history->fresh()->closing_balance_minutes)->toBe(360)
        ->and($assignment->fresh()->doctor_id)->toBe($doctors[0]->id);
});

it('warns when the previous confirmed history changes after generation', function () {
    [$admin, $doctors] = historyFixture();
    $this->actingAs($admin)->post(route('initial-workload.save', ['year' => 2026, 'month' => 9]), baselinePayload($doctors, '0'))->assertRedirect();
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    $this->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    $generatedAt = $october->fresh()->last_generated_at;
    $this->travel(2)->seconds();
    $this->post(route('initial-workload.save', ['year' => 2026, 'month' => 9]), baselinePayload($doctors, '6'))->assertRedirect();
    $warnings = app(RosterDraftValidationService::class)->validate($october->fresh());
    expect(collect($warnings)->contains(fn (array $item): bool => $item['severity'] === 'Warning' && str_contains($item['message'], 'history changed')))->toBeTrue()
        ->and($october->fresh()->last_generated_at?->equalTo($generatedAt))->toBeTrue();
    $this->travel(2)->seconds();
    $this->post(route('rosters.regenerate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    $warnings = app(RosterDraftValidationService::class)->validate($october->fresh());
    expect(collect($warnings)->contains(fn (array $item): bool => str_contains($item['message'], 'history changed')))->toBeFalse();
});
