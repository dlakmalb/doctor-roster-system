<?php

use App\Enums\ActualWorkExceptionType;
use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function lifecycleFixture(bool $filled = true): array
{
    $admin = User::factory()->create();
    $doctors = collect(['A', 'B', 'C', 'D'])->map(fn (string $code): Doctor => Doctor::create(['name' => "Doctor $code", 'short_code' => $code, 'is_active' => true]));
    $type = ShiftType::create(['code' => 'test_day', 'name' => 'Test Day', 'start_time' => '08:00:00', 'end_time' => '14:00:00', 'duration_minutes' => 360, 'main_count' => 1, 'optional_count' => 1, 'is_overnight' => false, 'is_active' => true]);
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id, 'last_generated_at' => now()->subDay()]);
    $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-05', 'shift_type_id' => $type->id]);
    if ($filled) {
        RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
        RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    }

    return [$admin, $doctors, $type, $roster, $shift];
}

function lifecycleUrl(string $action): string
{
    return route("rosters.$action", ['year' => 2026, 'month' => 10]);
}

it('requires authentication for lifecycle and document routes', function () {
    lifecycleFixture();

    $this->post(lifecycleUrl('finalize'))->assertRedirect(route('login'));
    $this->post(lifecycleUrl('reopen'))->assertRedirect(route('login'));
    $this->get(lifecycleUrl('print'))->assertRedirect(route('login'));
    $this->get(lifecycleUrl('pdf'))->assertRedirect(route('login'));
});

it('blocks missing Main and Optional slots and hard invalid assignments', function () {
    [$admin, $doctors, , $roster, $shift] = lifecycleFixture(false);
    $this->actingAs($admin);

    $this->postJson(lifecycleUrl('finalize'))->assertOk()->assertJsonPath('status', 'errors')->assertJsonCount(2, 'errors')
        ->assertJsonPath('errors.0.message', 'Oct 5 Test Day main slot 1 is unfilled.');
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'errors')->assertJsonCount(1, 'errors');
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $doctors[0]->update(['is_active' => false]);
    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'errors')->assertJsonFragment(['target' => "slot-{$shift->id}-main-1"]);

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->fresh()->finalized_at)->toBeNull()
        ->and($roster->fresh()->finalized_by)->toBeNull();
});

it('finalizes a valid roster without changing its plan and refuses a second finalization', function () {
    [$admin, , , $roster] = lifecycleFixture();
    $before = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $generatedAt = $roster->last_generated_at;
    $this->actingAs($admin);

    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'finalized');

    expect($roster->fresh()->status)->toBe(RosterStatus::Final)
        ->and($roster->fresh()->finalized_at)->not->toBeNull()
        ->and($roster->fresh()->finalized_by)->toBe($admin->id)
        ->and($roster->fresh()->updated_by)->toBe($admin->id)
        ->and($roster->fresh()->last_generated_at->equalTo($generatedAt))->toBeTrue();
    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($before);
    $finalizedAt = $roster->fresh()->finalized_at;
    $this->travel(2)->minutes();
    $this->postJson(lifecycleUrl('finalize'))->assertUnprocessable()->assertJsonValidationErrors('roster');
    expect($roster->fresh()->finalized_at->equalTo($finalizedAt))->toBeTrue();
});

it('requires confirmation of the current warnings and rejects stale confirmation', function () {
    [$admin, $doctors, $type, $roster] = lifecycleFixture();
    DoctorRequest::create(['doctor_id' => $doctors[2]->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-05', 'shift_type_id' => $type->id]);
    $this->actingAs($admin);

    $first = $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'confirmation_required')->assertJsonCount(1, 'warnings');
    $signature = $first->json('warning_signature');
    DoctorRequest::create(['doctor_id' => $doctors[3]->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-05', 'shift_type_id' => $type->id]);
    $updated = $this->postJson(lifecycleUrl('finalize'), ['warning_signature' => $signature])->assertJsonPath('status', 'stale_confirmation')->assertJsonCount(2, 'warnings');
    expect($updated->json('warning_signature'))->not->toBe($signature);
    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);

    $this->postJson(lifecycleUrl('finalize'), ['warning_signature' => $updated->json('warning_signature')])->assertJsonPath('status', 'finalized');
    expect($roster->fresh()->status)->toBe(RosterStatus::Final);
    $this->assertDatabaseCount('doctor_requests', 2);
    $this->post(lifecycleUrl('reopen'))->assertRedirect();
    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'confirmation_required')->assertJsonCount(2, 'warnings');
});

it('rechecks hard errors when warning confirmation arrives', function () {
    [$admin, $doctors, $type, $roster] = lifecycleFixture();
    DoctorRequest::create(['doctor_id' => $doctors[2]->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-05', 'shift_type_id' => $type->id]);
    $first = $this->actingAs($admin)->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'confirmation_required');
    $doctors[0]->update(['is_active' => false]);

    $this->postJson(lifecycleUrl('finalize'), ['warning_signature' => $first->json('warning_signature')])->assertJsonPath('status', 'errors');

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);
});

it('reopens and refinalizes a roster while preserving plan and latest metadata', function () {
    [$admin, $doctors, , $roster, $shift] = lifecycleFixture();
    $this->actingAs($admin);
    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'finalized');
    $firstFinalizedAt = $roster->fresh()->finalized_at;
    $generatedAt = $roster->last_generated_at;
    $assignmentsBeforeReopen = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $this->travel(5)->minutes();

    $this->post(lifecycleUrl('reopen'))->assertRedirect(lifecycleUrl('show'));
    expect($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->fresh()->reopened_at)->not->toBeNull()
        ->and($roster->fresh()->reopened_by)->toBe($admin->id)
        ->and($roster->fresh()->updated_by)->toBe($admin->id)
        ->and($roster->fresh()->last_generated_at->equalTo($generatedAt))->toBeTrue()
        ->and($roster->fresh()->finalized_at->equalTo($firstFinalizedAt))->toBeTrue();
    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($assignmentsBeforeReopen);
    $reopenedAt = $roster->fresh()->reopened_at;
    $this->post(lifecycleUrl('reopen'))->assertSessionHasErrors('roster');
    expect($roster->fresh()->reopened_at->equalTo($reopenedAt))->toBeTrue();
    $assignment = $shift->assignments()->where('role', 'main')->firstOrFail();
    $this->postJson(route('rosters.assignments.edit', ['year' => 2026, 'month' => 10]), [
        'operation' => 'replace', 'shift_id' => $shift->id, 'role' => 'main', 'slot_number' => 1,
        'expected_assignment_id' => $assignment->id, 'expected_doctor_id' => $assignment->doctor_id,
        'doctor_id' => $doctors[2]->id, 'confirm_soft_override' => true,
    ])->assertOk();
    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'finalized');

    expect($roster->fresh()->finalized_at->greaterThan($firstFinalizedAt))->toBeTrue()
        ->and($roster->fresh()->finalized_by)->toBe($admin->id)
        ->and($roster->fresh()->last_generated_at->equalTo($generatedAt))->toBeTrue();
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'main')->value('doctor_id'))->toBe($doctors[2]->id);
});

it('blocks reopening after confirmation or exceptions without mutating history', function () {
    [$admin, $doctors, , $roster, $shift] = lifecycleFixture();
    $roster->update(['status' => RosterStatus::Final, 'actual_work_confirmed_at' => now()]);
    $this->actingAs($admin)->post(lifecycleUrl('reopen'))->assertSessionHasErrors('roster');
    expect($roster->fresh()->status)->toBe(RosterStatus::Final);
    $this->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page->component('roster')->where('can_reopen', false));
    $roster->update(['actual_work_confirmed_at' => null]);
    $workload = DoctorMonthlyWorkload::create(['doctor_id' => $doctors[0]->id, 'roster_id' => $roster->id, 'year' => 2026, 'month' => 10, 'source' => DoctorMonthlyWorkloadSource::System, 'actual_worked_minutes' => 360]);
    $assignment = $shift->assignments()->where('role', 'main')->firstOrFail();
    $exception = ActualWorkException::create(['roster_shift_id' => $shift->id, 'planned_assignment_id' => $assignment->id, 'exception_type' => ActualWorkExceptionType::Replacement, 'actual_doctor_id' => $doctors[2]->id, 'recorded_by' => $admin->id]);

    $this->post(lifecycleUrl('reopen'))->assertSessionHasErrors('roster');

    expect($roster->fresh()->status)->toBe(RosterStatus::Final);
    $this->assertModelExists($exception);
    expect($exception->fresh()->actual_doctor_id)->toBe($doctors[2]->id);
    expect($workload->fresh()->actual_worked_minutes)->toBe(360);
    $this->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page->component('roster')->where('can_reopen', false));
});

it('reports reopen availability for an untouched Final roster', function () {
    [$admin, , , $roster] = lifecycleFixture();
    $roster->update(['status' => RosterStatus::Final]);

    $this->actingAs($admin)->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page->component('roster')->where('can_reopen', true));
});

it('invalidates manual Undo across finalize and reopen', function () {
    [$admin, $doctors, , , $shift] = lifecycleFixture();
    $assignment = $shift->assignments()->where('role', 'main')->firstOrFail();
    $this->actingAs($admin)->postJson(route('rosters.assignments.edit', ['year' => 2026, 'month' => 10]), [
        'operation' => 'replace',
        'shift_id' => $shift->id,
        'role' => 'main',
        'slot_number' => 1,
        'expected_assignment_id' => $assignment->id,
        'expected_doctor_id' => $assignment->doctor_id,
        'doctor_id' => $doctors[2]->id,
        'confirm_soft_override' => true,
    ])->assertOk();
    $this->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page->component('roster')->where('can_undo', true));

    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'finalized');
    $this->postJson(route('rosters.assignments.undo', ['year' => 2026, 'month' => 10]))->assertUnprocessable();
    $this->post(lifecycleUrl('reopen'))->assertRedirect();
    $this->postJson(route('rosters.assignments.undo', ['year' => 2026, 'month' => 10]))->assertUnprocessable()->assertJsonValidationErrors('edit');
    $this->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page->component('roster')->where('can_undo', false));
    expect($shift->assignments()->where('role', 'main')->value('doctor_id'))->toBe($doctors[2]->id);
});
