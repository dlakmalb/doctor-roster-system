<?php

use App\Enums\ActualWorkExceptionType;
use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorMonthlyParticipationService;
use App\Services\RosterDraftContext;
use App\Services\RosterDraftValidationService;
use App\Services\RosterManualEditService;
use App\Services\RosterPlanningHistoryService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function lifecycleFixture(bool $filled = true): array
{
    $admin = User::factory()->create();
    $doctors = collect(['A', 'B', 'C', 'D'])->map(fn (string $code): Doctor => Doctor::create(['name' => "Doctor $code", 'short_code' => $code, 'is_active' => true]));
    $type = ShiftType::create(['code' => 'test_day', 'name' => 'Test Day', 'start_time' => '08:00:00', 'end_time' => '14:00:00', 'duration_minutes' => 360, 'main_count' => 1, 'optional_count' => 1, 'is_overnight' => false, 'is_active' => true]);
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id, 'last_generated_at' => now()->subDay()]);
    app(DoctorMonthlyParticipationService::class)->snapshotRoster($roster, $doctors);
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

it('keeps an older Final roster viewable after a new doctor is added', function () {
    [$admin, $doctors, $type] = lifecycleFixture();
    $previous = Roster::create(['year' => 2026, 'month' => 8, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    $roster = Roster::create(['year' => 2026, 'month' => 9, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    app(DoctorMonthlyParticipationService::class)->snapshotRoster($previous, $doctors);
    app(DoctorMonthlyParticipationService::class)->snapshotRoster($roster, $doctors);
    $previousShift = RosterShift::create(['roster_id' => $previous->id, 'shift_date' => '2026-08-05', 'shift_type_id' => $type->id]);
    $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-09-05', 'shift_type_id' => $type->id]);
    RosterAssignment::create(['roster_shift_id' => $previousShift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $assignments = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $this->travelTo($roster->created_at->copy()->addMonth());
    $newDoctor = Doctor::create(['name' => 'Doctor New', 'short_code' => 'NEW', 'is_active' => true]);

    $items = app(RosterDraftValidationService::class)->validate($roster->fresh());
    $historicalWorkload = app(RosterPlanningHistoryService::class)->forMonth(2026, 9, false);
    expect(collect($items)->pluck('code')->contains('participation_snapshot_integrity'))->toBeFalse()
        ->and($historicalWorkload->has($newDoctor->id))->toBeFalse()
        ->and(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($assignments);

    $this->actingAs($admin)->get(route('rosters.show', ['year' => 2026, 'month' => 9]))->assertInertia(fn (Assert $page) => $page
        ->component('roster')
        ->where('status', 'final')
        ->where('conflicts', []));
});

it('detects a deleted participation row for a doctor present at snapshot time', function () {
    [, $doctors, , $roster] = lifecycleFixture();
    $roster->update(['status' => RosterStatus::Final]);
    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $doctors[3]->id)->delete();

    expect(collect(app(RosterDraftValidationService::class)->validate($roster))->pluck('code')->contains('participation_snapshot_integrity'))->toBeTrue();
});

it('resets participation validation state when a draft context loads another roster', function () {
    [$admin, $doctors, $type, $draft] = lifecycleFixture();
    $doctors[3]->update(['is_active' => false]);
    $context = app(RosterDraftContext::class);
    $context->load($draft);
    expect($context->participationPopulationMatches)->toBeFalse();
    DoctorMonthlyParticipation::query()->where('roster_id', $draft->id)->where('doctor_id', $doctors[3]->id)->delete();
    $context->load($draft);
    expect($context->participationSnapshotIntegrityMatches)->toBeFalse()
        ->and($context->participationPopulationMatches)->toBeTrue();

    $historicalFinal = Roster::create(['year' => 2026, 'month' => 9, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    app(DoctorMonthlyParticipationService::class)->snapshotRoster($historicalFinal, $doctors->where('is_active', true));
    $shift = RosterShift::create(['roster_id' => $historicalFinal->id, 'shift_date' => '2026-09-05', 'shift_type_id' => $type->id]);
    $doctors[0]->update(['is_active' => false]);
    $context->load($historicalFinal);

    expect($context->participationPopulationMatches)->toBeTrue()
        ->and($context->participationSnapshotIntegrityMatches)->toBeTrue()
        ->and($context->hardReasons($doctors[0]->id, $shift, []))->toBe([]);
});

it('keeps a Final roster valid and unchanged when global active status changes later', function () {
    [$admin, $doctors, , $roster] = lifecycleFixture();
    $newDoctor = $doctors[3];
    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $newDoctor->id)->update(['is_participating' => false]);
    $newDoctor->update(['is_active' => false]);
    $roster->update(['status' => RosterStatus::Final]);
    $assignmentsBefore = RosterAssignment::query()->orderBy('id')->get()->toArray();

    $doctors[0]->update(['is_active' => false]);
    $newDoctor->update(['is_active' => true]);

    $items = app(RosterDraftValidationService::class)->validate($roster->fresh());
    expect(collect($items)->where('severity', 'Error')->all())->toBe([])
        ->and(collect($items)->contains(fn (array $item): bool => str_contains($item['message'], 'inactive')))->toBeFalse()
        ->and(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($assignmentsBefore);

    $this->actingAs($admin)->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page
        ->component('roster')
        ->where('status', 'final')
        ->where('conflicts', []));
});

it('reports population drift on a Draft roster', function () {
    [$admin, $doctors, , $roster] = lifecycleFixture();
    $doctors[3]->update(['is_active' => false]);

    $items = app(RosterDraftValidationService::class)->validate($roster);
    expect(collect($items)->pluck('code')->contains('participation_population_mismatch'))->toBeTrue();

    $this->actingAs($admin)->postJson(lifecycleUrl('finalize'))
        ->assertJsonPath('status', 'errors')
        ->assertJsonFragment(['code' => 'participation_population_mismatch']);
});

it('keeps Draft restrictions after reopening a roster with status drift', function () {
    [$admin, $doctors, , $roster, $shift] = lifecycleFixture();
    $this->actingAs($admin)->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'finalized');
    $doctors[3]->update(['is_active' => false]);
    $this->post(lifecycleUrl('reopen'))->assertRedirect(lifecycleUrl('show'));

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and(DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $doctors[3]->id)->value('is_participating'))->toBeTrue();

    expect(fn () => app(RosterManualEditService::class)->edit($roster, $admin, 'replace', [
        'shift_id' => $shift->id,
        'role' => 'main',
        'slot_number' => 1,
        'expected_assignment_id' => null,
        'expected_doctor_id' => null,
        'doctor_id' => $doctors[2]->id,
    ], true))->toThrow(ValidationException::class);

    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'errors')
        ->assertJsonFragment(['code' => 'participation_population_mismatch']);
});

it('still reports genuine scheduling and participation snapshot integrity errors on Final rosters', function () {
    [, $doctors, , $roster, $shift] = lifecycleFixture();
    $roster->update(['status' => RosterStatus::Final]);
    DoctorRequest::create([
        'doctor_id' => $doctors[0]->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => $shift->shift_date,
        'shift_type_id' => $shift->shift_type_id,
    ]);

    $items = app(RosterDraftValidationService::class)->validate($roster);
    expect(collect($items)->contains(fn (array $item): bool => $item['severity'] === 'Error' && $item['code'] === 'hard_conflict' && str_contains($item['message'], 'Day-Off')))->toBeTrue();

    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $doctors[3]->id)->delete();
    $items = app(RosterDraftValidationService::class)->validate($roster);
    expect(collect($items)->pluck('code')->contains('participation_snapshot_integrity'))->toBeTrue();
});

it('reports multiple Main duties with the dates of the weekend period', function () {
    $admin = User::factory()->create();
    $doctor = Doctor::create(['name' => 'Dr Ganga', 'short_code' => 'G', 'is_active' => true]);
    $roster = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    app(DoctorMonthlyParticipationService::class)->snapshotRoster($roster, collect([$doctor]));
    $nightType = ShiftType::create(['code' => 'weekday_night', 'name' => 'Weekday Night', 'start_time' => '20:00:00', 'end_time' => '08:00:00', 'duration_minutes' => 720, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => true, 'is_active' => true]);
    $weekendType = ShiftType::create(['code' => 'weekend_day', 'name' => 'Weekend Day', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'duration_minutes' => 480, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => false, 'is_active' => true]);

    foreach ([['2026-11-27', $nightType], ['2026-11-29', $weekendType]] as [$date, $shiftType]) {
        $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => $date, 'shift_type_id' => $shiftType->id]);
        RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    }

    $warning = collect(app(RosterDraftValidationService::class)->validate($roster))
        ->firstWhere('code', 'multiple_weekend_main');

    expect($warning)->not->toBeNull()
        ->and($warning['severity'])->toBe('Warning')
        ->and($warning['message'])->toBe("Dr Ganga has multiple Main duties during the weekend of Nov 28\u{2013}29.");
});

it('validates missing Main and Optional slots by role and clears each warning when filled', function () {
    [, $doctors, , $roster, $shift] = lifecycleFixture(false);
    $validation = app(RosterDraftValidationService::class);

    $missing = collect($validation->validate($roster))->whereIn('code', ['unfilled_main_slot', 'unfilled_optional_slot']);
    expect($missing->pluck('severity', 'code')->all())->toBe([
        'unfilled_main_slot' => 'Error',
        'unfilled_optional_slot' => 'Warning',
    ]);

    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    expect(collect($validation->validate($roster))->whereIn('code', ['unfilled_main_slot', 'unfilled_optional_slot'])->pluck('code')->values()->all())
        ->toBe(['unfilled_optional_slot']);

    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    expect(collect($validation->validate($roster))->whereIn('code', ['unfilled_main_slot', 'unfilled_optional_slot'])->isEmpty())->toBeTrue();
});

it('requires authentication for lifecycle and document routes', function () {
    lifecycleFixture();

    $this->post(lifecycleUrl('finalize'))->assertRedirect(route('login'));
    $this->post(lifecycleUrl('reopen'))->assertRedirect(route('login'));
    $this->get(lifecycleUrl('print'))->assertRedirect(route('login'));
    $this->get(lifecycleUrl('pdf'))->assertRedirect(route('login'));
});

it('blocks missing Main slots and hard invalid assignments while reporting Optional warnings', function () {
    [$admin, $doctors, , $roster, $shift] = lifecycleFixture(false);
    $this->actingAs($admin);

    $this->postJson(lifecycleUrl('finalize'))->assertOk()->assertJsonPath('status', 'errors')->assertJsonCount(1, 'errors')
        ->assertJsonPath('errors.0.code', 'unfilled_main_slot')
        ->assertJsonPath('errors.0.message', 'Oct 5 Test Day main slot 1 is unfilled.')
        ->assertJsonCount(1, 'warnings')
        ->assertJsonPath('warnings.0.code', 'unfilled_optional_slot');
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'confirmation_required')->assertJsonCount(0, 'errors')->assertJsonCount(1, 'warnings');
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $doctors[0]->update(['is_active' => false]);
    $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'errors')->assertJsonFragment(['target' => "slot-{$shift->id}-main-1"]);

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->fresh()->finalized_at)->toBeNull()
        ->and($roster->fresh()->finalized_by)->toBeNull();
});

it('rejects finalization when an assigned doctor has a monthly shift restriction', function () {
    [$admin, $doctors, $type, $roster, $shift] = lifecycleFixture();
    DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $doctors[0]->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $type->id,
    ]);

    $this->actingAs($admin)->postJson(lifecycleUrl('finalize'))
        ->assertOk()
        ->assertJsonPath('status', 'errors')
        ->assertJsonFragment(['code' => 'hard_conflict'])
        ->assertJsonFragment(['target' => "slot-{$shift->id}-main-1"]);

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->fresh()->finalized_at)->toBeNull();
});

it('rejects finalization before generation and keeps the roster in Draft', function () {
    [$admin, , , $roster] = lifecycleFixture(false);
    $roster->update(['last_generated_at' => null]);

    $this->actingAs($admin)->postJson(lifecycleUrl('finalize'))
        ->assertOk()
        ->assertJsonPath('status', 'errors')
        ->assertJsonCount(1, 'errors')
        ->assertJsonPath('errors.0.code', 'unfilled_main_slot')
        ->assertJsonPath('warnings.0.code', 'unfilled_optional_slot');

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->fresh()->last_generated_at)->toBeNull();

    $this->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page
        ->component('roster')
        ->where('has_generated', false)
        ->where('conflicts.0.code', 'unfilled_main_slot'));
});

it('finalizes with an unfilled Optional slot after warning confirmation', function () {
    [$admin, $doctors, , $roster, $shift] = lifecycleFixture(false);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->actingAs($admin);

    $first = $this->postJson(lifecycleUrl('finalize'))
        ->assertJsonPath('status', 'confirmation_required')
        ->assertJsonCount(0, 'errors')
        ->assertJsonCount(1, 'warnings')
        ->assertJsonPath('warnings.0.code', 'unfilled_optional_slot');
    expect($first->json('warning_signature'))->toBeString()->not->toBeEmpty();
    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);

    $this->postJson(lifecycleUrl('finalize'), ['warning_signature' => $first->json('warning_signature')])
        ->assertJsonPath('status', 'finalized');
    expect($roster->fresh()->status)->toBe(RosterStatus::Final);
    $this->get(lifecycleUrl('show'))->assertInertia(fn (Assert $page) => $page
        ->component('roster')
        ->where('days.0.shifts.0.optional.0.severity', 'Warning'));
});

it('does not let warning confirmation bypass a missing Main slot', function () {
    [$admin, , , $roster, $shift] = lifecycleFixture();
    $shift->assignments()->where('role', RosterAssignmentRole::Main)->delete();
    $this->actingAs($admin)->postJson(lifecycleUrl('finalize'), ['warning_signature' => str_repeat('a', 64)])
        ->assertJsonPath('status', 'errors')
        ->assertJsonPath('errors.0.code', 'unfilled_main_slot')
        ->assertJsonCount(0, 'warnings');

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);
});

it('rejects stale confirmation when Preferred Work joins an Optional vacancy', function () {
    [$admin, $doctors, , $roster, $shift] = lifecycleFixture(false);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->actingAs($admin);
    $first = $this->postJson(lifecycleUrl('finalize'))->assertJsonPath('status', 'confirmation_required');
    DoctorRequest::create(['doctor_id' => $doctors[2]->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-05', 'shift_type_id' => $shift->shift_type_id]);

    $updated = $this->postJson(lifecycleUrl('finalize'), ['warning_signature' => $first->json('warning_signature')])
        ->assertJsonPath('status', 'stale_confirmation')
        ->assertJsonCount(2, 'warnings');
    expect($updated->json('warning_signature'))->not->toBe($first->json('warning_signature'));
    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);
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

it('blocks finalization when global doctor status has drifted from saved participation', function () {
    [$admin, $doctors, , $roster] = lifecycleFixture();
    $outsider = $doctors[3];
    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $outsider->id)->update(['is_participating' => false]);
    $outsider->update(['is_active' => false]);
    $outsider->update(['is_active' => true]);

    $this->actingAs($admin)->postJson(lifecycleUrl('finalize'))
        ->assertJsonPath('status', 'errors')
        ->assertJsonPath('errors.0.code', 'participation_population_mismatch');

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
