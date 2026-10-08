<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\User;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

function editingFixture(): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $day = RosterShift::query()->whereDate('shift_date', '2026-10-06')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_day'))->firstOrFail();
    $later = RosterShift::query()->whereDate('shift_date', '2026-10-08')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_day'))->firstOrFail();
    $doctors = Doctor::query()->where('is_active', true)->orderBy('short_code')->take(4)->get();

    return [$admin, $roster, $day, $later, $doctors];
}

function editPayload(RosterShift $shift, string $role, int $slot, ?RosterAssignment $assignment = null): array
{
    return [
        'shift_id' => $shift->id,
        'role' => $role,
        'slot_number' => $slot,
        'expected_assignment_id' => $assignment?->id,
        'expected_doctor_id' => $assignment?->doctor_id,
    ];
}

function editingUrl(string $action): string
{
    return route("rosters.assignments.$action", ['year' => 2026, 'month' => 10]);
}

function optionsUrl(RosterShift $shift, string $role, int $slot, ?RosterAssignment $assignment = null): string
{
    $query = array_map(fn ($value): string => $value === null ? '' : (string) $value, editPayload($shift, $role, $slot, $assignment));

    return route('rosters.assignment-options', ['year' => 2026, 'month' => 10]).'?'.http_build_query($query);
}

function syncRosterParticipationToActiveDoctors(): void
{
    $roster = Roster::query()->where('year', 2026)->where('month', 10)->firstOrFail();
    foreach (DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->get() as $participation) {
        $participation->update(['is_participating' => (bool) Doctor::query()->whereKey($participation->doctor_id)->value('is_active')]);
    }
}

it('requires authentication and rejects all manual actions on Final rosters', function () {
    [$admin, $roster, $shift, , $doctors] = editingFixture();
    $first = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $second = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 2]);
    $this->getJson(route('rosters.assignment-options', ['year' => 2026, 'month' => 10]))->assertUnauthorized();
    $this->postJson(editingUrl('edit'), [])->assertUnauthorized();
    $roster->update(['status' => RosterStatus::Final]);
    $this->actingAs($admin)->getJson(optionsUrl($shift, 'main', 1, $first))->assertUnprocessable();
    foreach ([
        ['operation' => 'replace', ...editPayload($shift, 'main', 1, $first), 'doctor_id' => $doctors[2]->id],
        ['operation' => 'clear', ...editPayload($shift, 'main', 1, $first)],
        ['operation' => 'swap', ...editPayload($shift, 'main', 1, $first), 'target_shift_id' => $shift->id, 'target_role' => 'main', 'target_slot_number' => 2, 'target_expected_assignment_id' => $second->id, 'target_expected_doctor_id' => $second->doctor_id],
    ] as $payload) {
        $this->postJson(editingUrl('edit'), $payload)->assertUnprocessable()->assertJsonValidationErrors('edit');
    }
    $this->postJson(editingUrl('undo'))->assertUnprocessable()->assertJsonValidationErrors('edit');
    expect($first->fresh()->doctor_id)->toBe($doctors[0]->id);
});

it('assigns and replaces both roles, autosaves metadata, and undoes the most recent action', function () {
    [$admin, $roster, $shift, , $doctors] = editingFixture();
    $this->actingAs($admin);
    $this->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($shift, 'main', 1), 'doctor_id' => $doctors[0]->id, 'confirm_soft_override' => true])->assertOk();
    $main = RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'main')->where('slot_number', 1)->firstOrFail();
    $this->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($shift, 'optional', 1), 'doctor_id' => $doctors[1]->id, 'confirm_soft_override' => true])->assertOk();
    $optional = RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'optional')->where('slot_number', 1)->firstOrFail();
    $this->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($shift, 'main', 1, $main), 'doctor_id' => $doctors[2]->id, 'confirm_soft_override' => true])->assertOk();
    $replacement = RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'main')->where('slot_number', 1)->firstOrFail();
    expect($replacement->doctor_id)->toBe($doctors[2]->id)
        ->and($optional->fresh()->doctor_id)->toBe($doctors[1]->id)
        ->and($roster->fresh()->updated_by)->toBe($admin->id)
        ->and($roster->fresh()->last_generated_at)->toBeNull();
    $this->postJson(editingUrl('undo'))->assertOk();
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'main')->where('slot_number', 1)->value('doctor_id'))->toBe($doctors[0]->id);
    $this->postJson(editingUrl('undo'))->assertUnprocessable();
});

it('allows a manual assignment in Weekend Day Optional slot seven and rejects slot eight', function () {
    [$admin, , , , $doctors] = editingFixture();
    $shift = RosterShift::query()->whereDate('shift_date', '2026-10-03')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->firstOrFail();
    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'replace', ...editPayload($shift, 'optional', 7),
        'doctor_id' => $doctors[0]->id, 'confirm_soft_override' => true,
    ])->assertOk();

    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)
        ->where('role', RosterAssignmentRole::Optional)->where('slot_number', 7)->value('doctor_id'))->toBe($doctors[0]->id);
    $this->getJson(optionsUrl($shift, 'optional', 8))->assertUnprocessable();
});

it('does not manually assign a doctor outside the saved monthly participation population', function () {
    [$admin, $roster, $shift, , $doctors] = editingFixture();
    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $doctors[2]->id)->update(['is_participating' => false]);

    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'replace', ...editPayload($shift, 'main', 1), 'doctor_id' => $doctors[2]->id, 'confirm_soft_override' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('edit');

    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('doctor_id', $doctors[2]->id)->exists())->toBeFalse();
});

it('keeps a manual assignment in the pre-generation presentation', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'replace',
        ...editPayload($shift, 'main', 1),
        'doctor_id' => $doctors[0]->id,
        'confirm_soft_override' => true,
    ])->assertOk();

    $this->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('roster')
            ->where('has_generated', false)
            ->where('has_assignments', true)
            ->where('summary.filled_main', 1)
            ->where('summary.filled_optional', 0)
            ->where('conflicts.0.code', 'unfilled_main_slot'));
});

it('preserves genuine hard errors alongside pre-generation unfilled-slot errors', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    DoctorRequest::create([
        'doctor_id' => $doctors[0]->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => $shift->shift_date,
        'shift_type_id' => $shift->shift_type_id,
    ]);
    RosterAssignment::create([
        'roster_shift_id' => $shift->id,
        'doctor_id' => $doctors[0]->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);
    $this->actingAs($admin)->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('roster')
            ->where('has_generated', false)
            ->where('conflicts', fn (Collection $items): bool => $items->contains(fn (array $item): bool => $item['code'] === 'hard_conflict')
                && $items->contains(fn (array $item): bool => $item['code'] === 'unfilled_optional_slot')));
});

it('keeps same-shift auto-swap available when replacing an occupied slot, then undoes', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $main = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $optional = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'replace', ...editPayload($shift, 'main', 1, $main), 'doctor_id' => $doctors[1]->id,
        'expected_source_assignment_id' => $optional->id, 'confirm_soft_override' => true,
    ])->assertOk();
    $rows = RosterAssignment::query()->where('roster_shift_id', $shift->id)->get();
    expect($rows->where('role', RosterAssignmentRole::Main)->first()->doctor_id)->toBe($doctors[1]->id)
        ->and($rows->where('role', RosterAssignmentRole::Optional)->first()->doctor_id)->toBe($doctors[0]->id)
        ->and($rows->pluck('doctor_id')->unique())->toHaveCount(2);
    $this->postJson(editingUrl('undo'))->assertOk();
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'main')->value('doctor_id'))->toBe($doctors[0]->id);
});

it('keeps a same-shift doctor eligible when the target slot is occupied', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $main = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $optional = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);

    $this->actingAs($admin)->getJson(optionsUrl($shift, 'main', 1, $main))
        ->assertOk()->assertJsonFragment(['id' => $doctors[1]->id, 'eligible' => true, 'source_assignment_id' => $optional->id]);
});

it('promotes an Optional assignment into an empty Main slot', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $optional = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);

    $this->actingAs($admin)->getJson(optionsUrl($shift, 'main', 1))
        ->assertOk()->assertJsonFragment(['id' => $doctors[0]->id, 'eligible' => true, 'source_assignment_id' => $optional->id]);

    $this->postJson(editingUrl('edit'), [
        'operation' => 'replace',
        ...editPayload($shift, 'main', 1),
        'doctor_id' => $doctors[0]->id,
        'expected_source_assignment_id' => $optional->id,
        'confirm_soft_override' => true,
    ])->assertOk()->assertJsonPath('status', 'saved');

    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', RosterAssignmentRole::Main)->where('slot_number', 1)->value('doctor_id'))->toBe($doctors[0]->id)
        ->and(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', RosterAssignmentRole::Optional)->exists())->toBeFalse();
});

it('marks same-shift doctors unavailable for an empty slot and rejects a crafted assignment', function () {
    [$admin] = editingFixture();
    $shift = RosterShift::query()->whereDate('shift_date', '2026-10-06')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_evening'))->firstOrFail();
    $sameDateShift = RosterShift::query()->whereDate('shift_date', '2026-10-06')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_day'))->firstOrFail();
    $doctors = Doctor::query()->where('is_active', true)->orderBy('short_code')->take(5)->get();
    foreach ($doctors->take(3) as $index => $doctor) {
        RosterAssignment::create([
            'roster_shift_id' => $shift->id,
            'doctor_id' => $doctor->id,
            'role' => RosterAssignmentRole::Main,
            'slot_number' => $index + 1,
        ]);
    }
    RosterAssignment::create([
        'roster_shift_id' => $sameDateShift->id,
        'doctor_id' => $doctors[3]->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);

    $this->actingAs($admin)->getJson(optionsUrl($shift, 'optional', 1))
        ->assertOk()
        ->assertJsonFragment(['id' => $doctors[0]->id, 'eligible' => false, 'reasons' => ['Already assigned to this shift.']])
        ->assertJsonFragment(['id' => $doctors[1]->id, 'eligible' => false, 'reasons' => ['Already assigned to this shift.']])
        ->assertJsonFragment(['id' => $doctors[2]->id, 'eligible' => false, 'reasons' => ['Already assigned to this shift.']])
        ->assertJsonFragment(['id' => $doctors[3]->id, 'eligible' => false, 'reasons' => ["{$doctors[3]->name} is already assigned to a shift starting on this date."]])
        ->assertJsonFragment(['id' => $doctors[4]->id, 'eligible' => true, 'reasons' => []]);

    $mainAssignment = RosterAssignment::query()->where('roster_shift_id', $shift->id)
        ->where('role', RosterAssignmentRole::Main)->where('slot_number', 1)->firstOrFail();
    $this->postJson(editingUrl('edit'), [
        'operation' => 'replace',
        ...editPayload($shift, 'optional', 1),
        'doctor_id' => $doctors[0]->id,
        'expected_source_assignment_id' => $mainAssignment->id,
        'confirm_soft_override' => true,
    ])->assertUnprocessable()->assertJsonPath('errors.edit.0', 'Already assigned to this shift.');

    expect($mainAssignment->fresh()->doctor_id)->toBe($doctors[0]->id)
        ->and(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', RosterAssignmentRole::Optional)->exists())->toBeFalse();
});

it('rejects a same-role move into another empty Main slot', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $main = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    $this->actingAs($admin)->getJson(optionsUrl($shift, 'main', 2))
        ->assertOk()->assertJsonFragment(['id' => $doctors[0]->id, 'eligible' => false, 'reasons' => ['Already assigned to this shift.']]);

    $this->postJson(editingUrl('edit'), [
        'operation' => 'replace',
        ...editPayload($shift, 'main', 2),
        'doctor_id' => $doctors[0]->id,
        'expected_source_assignment_id' => null,
        'confirm_soft_override' => true,
    ])->assertUnprocessable()->assertJsonPath('errors.edit.0', 'Already assigned to this shift.');

    expect($main->fresh()->doctor_id)->toBe($doctors[0]->id)
        ->and(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', RosterAssignmentRole::Main)->where('slot_number', 2)->exists())->toBeFalse();
});

it('swaps across shifts atomically and rejects stale expected state', function () {
    [$admin, , $firstShift, $secondShift, $doctors] = editingFixture();
    $first = RosterAssignment::create(['roster_shift_id' => $firstShift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $second = RosterAssignment::create(['roster_shift_id' => $secondShift->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $payload = ['operation' => 'swap', ...editPayload($firstShift, 'main', 1, $first), 'target_shift_id' => $secondShift->id, 'target_role' => 'main', 'target_slot_number' => 1, 'target_expected_assignment_id' => $second->id, 'target_expected_doctor_id' => $second->doctor_id, 'confirm_soft_override' => true];
    $this->actingAs($admin)->postJson(editingUrl('edit'), $payload)->assertOk();
    expect(RosterAssignment::query()->where('roster_shift_id', $firstShift->id)->value('doctor_id'))->toBe($doctors[1]->id)
        ->and(RosterAssignment::query()->where('roster_shift_id', $secondShift->id)->value('doctor_id'))->toBe($doctors[0]->id);
    $this->postJson(editingUrl('edit'), $payload)->assertUnprocessable()->assertJsonValidationErrors('edit');
    $this->postJson(editingUrl('undo'))->assertOk();
    expect(RosterAssignment::query()->where('roster_shift_id', $firstShift->id)->value('doctor_id'))->toBe($doctors[0]->id);
});

it('blocks hard conflicts even with confirmation and reports current setup conflicts', function () {
    [$admin, $roster, $shift, , $doctors] = editingFixture();
    $doctor = $doctors[0];
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => $shift->shift_date, 'shift_type_id' => $shift->shift_type_id]);
    $this->actingAs($admin)->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($shift, 'main', 1), 'doctor_id' => $doctor->id, 'confirm_soft_override' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('edit');
    $assignment = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $items = app(RosterDraftValidationService::class)->validate($roster);
    expect(collect($items)->contains(fn (array $item): bool => $item['severity'] === 'Error' && str_contains($item['message'], 'Day-Off') && $item['target'] === "slot-$shift->id-main-1"))->toBeTrue();
    expect($assignment->fresh())->not->toBeNull();
    DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10]);
    $doctor->update(['is_active' => false]);
    $items = app(RosterDraftValidationService::class)->validate($roster);
    expect(collect($items)->contains(fn (array $item): bool => str_contains($item['message'], 'excluded')))->toBeTrue()
        ->and(collect($items)->contains(fn (array $item): bool => str_contains($item['message'], 'inactive')))->toBeTrue();
});

it('returns picker metrics and exact Preferred Work while keeping ineligible doctors visible', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $doctor = $doctors[0];
    DoctorMonthlyWorkload::query()->updateOrCreate(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 9], ['actual_worked_minutes' => 0, 'closing_balance_minutes' => 120, 'actual_night_duty_count' => 2, 'optional_assignment_count' => 3]);
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $shift->shift_date, 'shift_type_id' => $shift->shift_type_id]);
    $this->actingAs($admin)->getJson(optionsUrl($shift, 'main', 1))
        ->assertOk()->assertJsonFragment(['name' => $doctor->name, 'short_code' => $doctor->short_code, 'preferred_work' => true, 'effective_workload_minutes' => 120, 'night_count' => 2, 'optional_count' => 3]);
});

it('requires confirmation for a soft override and for clearing fulfilled Preferred Work', function () {
    [$admin, $roster, $shift, , $doctors] = editingFixture();
    $preferred = $doctors[0];
    DoctorRequest::create(['doctor_id' => $preferred->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $shift->shift_date, 'shift_type_id' => $shift->shift_type_id]);
    $this->actingAs($admin);
    $this->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($shift, 'main', 1), 'doctor_id' => $preferred->id])->assertOk()->assertJsonPath('status', 'saved');
    $assignment = RosterAssignment::query()->where('roster_shift_id', $shift->id)->firstOrFail();
    $replacement = ['operation' => 'replace', ...editPayload($shift, 'main', 1, $assignment), 'doctor_id' => $doctors[1]->id];
    $this->postJson(editingUrl('edit'), $replacement)->assertOk()->assertJsonPath('status', 'confirmation_required');
    expect($assignment->fresh()->doctor_id)->toBe($preferred->id);
    $this->postJson(editingUrl('edit'), [...$replacement, 'confirm_soft_override' => true])->assertOk()->assertJsonPath('status', 'saved');
    $items = app(RosterDraftValidationService::class)->validate($roster);
    expect(collect($items)->contains(fn (array $item): bool => $item['severity'] === 'Warning' && str_contains($item['message'], 'Preferred Work')))->toBeTrue();
    $this->postJson(editingUrl('undo'))->assertOk();
    $assignment = RosterAssignment::query()->where('roster_shift_id', $shift->id)->firstOrFail();
    $clear = ['operation' => 'clear', ...editPayload($shift, 'main', 1, $assignment)];
    $this->postJson(editingUrl('edit'), $clear)->assertOk()->assertJsonPath('status', 'confirmation_required');
    $this->postJson(editingUrl('edit'), [...$clear, 'confirm_soft_override' => true])->assertOk()->assertJsonPath('status', 'saved');
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->exists())->toBeFalse();
    $this->postJson(editingUrl('undo'))->assertOk();
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->value('doctor_id'))->toBe($preferred->id);
});

it('does not let stale undo restore a changed slot or edit a different roster shift', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $other = app(RosterStructureService::class)->create(2026, 11, $admin);
    $otherShift = $other->shifts()->firstOrFail();
    $this->actingAs($admin)->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($otherShift, 'main', 1), 'doctor_id' => $doctors[0]->id, 'confirm_soft_override' => true])->assertNotFound();
    $this->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($shift, 'main', 1), 'doctor_id' => $doctors[0]->id, 'confirm_soft_override' => true])->assertOk();
    $saved = RosterAssignment::query()->where('roster_shift_id', $shift->id)->firstOrFail();
    $saved->update(['doctor_id' => $doctors[1]->id]);
    $this->postJson(editingUrl('undo'))->assertUnprocessable()->assertJsonValidationErrors('edit');
    expect($saved->fresh()->doctor_id)->toBe($doctors[1]->id);
});

it('invalidates manual undo after generation and preserves fixed manual assignments', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $this->actingAs($admin)->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($shift, 'main', 1), 'doctor_id' => $doctors[0]->id, 'confirm_soft_override' => true])->assertOk();
    $manual = RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'main')->where('slot_number', 1)->firstOrFail();
    $this->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    expect($manual->fresh()->doctor_id)->toBe($doctors[0]->id);
    $this->postJson(editingUrl('undo'))->assertUnprocessable();
    $this->post(route('rosters.regenerate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    expect(RosterAssignment::query()->whereKey($manual->id)->exists())->toBeFalse();
});

it('rejects inactive, excluded, Day-Off, same-date, and Night recovery replacements', function () {
    [$admin, , $day, , $doctors] = editingFixture();
    $tuesdayEvening = RosterShift::query()->whereDate('shift_date', '2026-10-06')->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_evening'))->firstOrFail();
    $mondayNight = RosterShift::query()->whereDate('shift_date', '2026-10-05')->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_night'))->firstOrFail();
    $wednesdayNight = RosterShift::query()->whereDate('shift_date', '2026-10-07')->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_night'))->firstOrFail();
    $candidateDoctors = Doctor::query()->where('is_active', true)->orderBy('short_code')->take(8)->get();
    $occupant = RosterAssignment::create(['roster_shift_id' => $day->id, 'doctor_id' => $candidateDoctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $candidateDoctors[1]->update(['is_active' => false]);
    DoctorMonthlyParticipation::query()->where('doctor_id', $candidateDoctors[1]->id)->where('year', 2026)->where('month', 10)->update(['is_participating' => false]);
    DoctorMonthlyExclusion::create(['doctor_id' => $candidateDoctors[2]->id, 'year' => 2026, 'month' => 10]);
    DoctorRequest::create(['doctor_id' => $candidateDoctors[3]->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => $day->shift_date, 'shift_type_id' => $day->shift_type_id]);
    RosterAssignment::create(['roster_shift_id' => $tuesdayEvening->id, 'doctor_id' => $candidateDoctors[4]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    RosterAssignment::create(['roster_shift_id' => $mondayNight->id, 'doctor_id' => $candidateDoctors[5]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    RosterAssignment::create(['roster_shift_id' => $mondayNight->id, 'doctor_id' => $candidateDoctors[6]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 2]);
    $this->actingAs($admin);
    foreach ([1 => 'inactive', 2 => 'excluded', 3 => 'Day-Off', 4 => 'starting on this date', 5 => 'next-day Night recovery'] as $index => $message) {
        $this->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($day, 'main', 1, $occupant), 'doctor_id' => $candidateDoctors[$index]->id, 'confirm_soft_override' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('edit')->assertSee($index === 1 ? 'not part of the team' : $message);
    }
    $this->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($wednesdayNight, 'main', 1), 'doctor_id' => $candidateDoctors[6]->id, 'confirm_soft_override' => true])
        ->assertUnprocessable()->assertSee('Night-to-Night recovery');
    expect($occupant->fresh()->doctor_id)->toBe($candidateDoctors[0]->id);
});

it('rejects a hard-invalid cross-shift swap without changing either assignment', function () {
    [$admin, , $day, $later, $doctors] = editingFixture();
    $first = RosterAssignment::create(['roster_shift_id' => $day->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $second = RosterAssignment::create(['roster_shift_id' => $later->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    DoctorRequest::create(['doctor_id' => $doctors[1]->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => $day->shift_date, 'shift_type_id' => $day->shift_type_id]);
    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'swap', ...editPayload($day, 'main', 1, $first),
        'target_shift_id' => $later->id, 'target_role' => 'main', 'target_slot_number' => 1,
        'target_expected_assignment_id' => $second->id, 'target_expected_doctor_id' => $second->doctor_id,
        'confirm_soft_override' => true,
    ])->assertUnprocessable()->assertSee('Day-Off');
    expect($first->fresh()->doctor_id)->toBe($doctors[0]->id)
        ->and($second->fresh()->doctor_id)->toBe($doctors[1]->id);
});

it('does not allow a direct cross-shift swap to bypass a monthly restriction', function () {
    [$admin, , $day, $later, $doctors] = editingFixture();
    $first = RosterAssignment::create(['roster_shift_id' => $day->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $second = RosterAssignment::create(['roster_shift_id' => $later->id, 'doctor_id' => $doctors[1]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $doctors[1]->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $day->shift_type_id,
    ]);

    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'swap', ...editPayload($day, 'main', 1, $first),
        'target_shift_id' => $later->id, 'target_role' => 'main', 'target_slot_number' => 1,
        'target_expected_assignment_id' => $second->id, 'target_expected_doctor_id' => $second->doctor_id,
        'confirm_soft_override' => true,
    ])->assertUnprocessable()->assertSee('restricted from this shift type');

    expect($first->fresh()->doctor_id)->toBe($doctors[0]->id)
        ->and($second->fresh()->doctor_id)->toBe($doctors[1]->id);
});

it('shows missing slots and an unfulfilled Preferred Work warning with jump targets', function () {
    [$admin, $roster, $day, , $doctors] = editingFixture();
    DoctorRequest::create(['doctor_id' => $doctors[0]->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $day->shift_date, 'shift_type_id' => $day->shift_type_id]);
    RosterAssignment::create(['roster_shift_id' => $day->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $items = app(RosterDraftValidationService::class)->validate($roster);
    expect(collect($items)->contains(fn (array $item): bool => $item['severity'] === 'Error' && $item['target'] === "slot-$day->id-main-1"))->toBeTrue()
        ->and(collect($items)->contains(fn (array $item): bool => $item['severity'] === 'Warning' && $item['target'] === "shift-$day->id" && str_contains($item['message'], 'Preferred Work')))->toBeTrue();
    $this->actingAs($admin)->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertOk()->assertInertia(fn ($page) => $page->component('roster')->has('conflicts')->where('status', 'draft'));
});

it('will not undo into a new hard conflict introduced by Monthly Setup', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $first = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'replace', ...editPayload($shift, 'main', 1, $first),
        'doctor_id' => $doctors[1]->id, 'confirm_soft_override' => true,
    ])->assertOk();
    DoctorRequest::create(['doctor_id' => $doctors[0]->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => $shift->shift_date, 'shift_type_id' => $shift->shift_type_id]);
    $this->postJson(editingUrl('undo'))->assertUnprocessable()->assertSee('Undo would restore an invalid assignment');
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->value('doctor_id'))->toBe($doctors[1]->id);
});

it('will not undo into a monthly restricted assignment', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $assignment = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctors[0]->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $this->actingAs($admin)->postJson(editingUrl('edit'), [
        'operation' => 'replace', ...editPayload($shift, 'main', 1, $assignment),
        'doctor_id' => $doctors[1]->id, 'confirm_soft_override' => true,
    ])->assertOk();
    DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $doctors[0]->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $shift->shift_type_id,
    ]);

    $this->postJson(editingUrl('undo'))->assertUnprocessable()->assertSee('Undo would restore an invalid assignment');
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->value('doctor_id'))->toBe($doctors[1]->id);
});

it('warns that Monday Night blocks Tuesday Preferred Work even with only one eligible doctor', function () {
    [$admin, , $tuesdayDay, , $doctors] = editingFixture();
    $doctor = $doctors[0];
    $mondayNight = RosterShift::query()->whereDate('shift_date', '2026-10-05')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_night'))->firstOrFail();
    Doctor::query()->where('id', '!=', $doctor->id)->update(['is_active' => false]);
    syncRosterParticipationToActiveDoctors();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $tuesdayDay->shift_date, 'shift_type_id' => $tuesdayDay->shift_type_id]);

    $this->actingAs($admin);
    $options = $this->getJson(optionsUrl($mondayNight, 'main', 1))->assertOk()->json('options');
    expect(collect($options)->where('eligible', true)->pluck('id')->all())->toBe([$doctor->id]);

    $payload = ['operation' => 'replace', ...editPayload($mondayNight, 'main', 1), 'doctor_id' => $doctor->id];
    $response = $this->postJson(editingUrl('edit'), $payload)->assertOk()->assertJsonPath('status', 'confirmation_required');
    expect(implode(' ', $response->json('warnings')))->toContain('Preferred Work Main request')->toContain('Oct 6');
    expect(RosterAssignment::query()->where('roster_shift_id', $mondayNight->id)->exists())->toBeFalse();

    $this->postJson(editingUrl('edit'), [...$payload, 'confirm_soft_override' => true])->assertOk()->assertJsonPath('status', 'saved');
    expect(RosterAssignment::query()->where('roster_shift_id', $mondayNight->id)->value('doctor_id'))->toBe($doctor->id);
});

it('warns that same-shift Optional blocks an exact Preferred Work Main request', function () {
    [$admin, $roster, $shift, , $doctors] = editingFixture();
    $doctor = $doctors[0];
    Doctor::query()->where('id', '!=', $doctor->id)->update(['is_active' => false]);
    syncRosterParticipationToActiveDoctors();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $shift->shift_date, 'shift_type_id' => $shift->shift_type_id]);
    $payload = ['operation' => 'replace', ...editPayload($shift, 'optional', 1), 'doctor_id' => $doctor->id];

    $response = $this->actingAs($admin)->postJson(editingUrl('edit'), $payload)->assertOk()->assertJsonPath('status', 'confirmation_required');
    expect(implode(' ', $response->json('warnings')))->toContain('Preferred Work Main request');
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->exists())->toBeFalse();
    $this->postJson(editingUrl('edit'), [...$payload, 'confirm_soft_override' => true])->assertOk()->assertJsonPath('status', 'saved');
    expect(RosterAssignment::query()->where('roster_shift_id', $shift->id)->where('role', 'optional')->value('doctor_id'))->toBe($doctor->id);
    expect(collect(app(RosterDraftValidationService::class)->validate($roster))->contains(fn (array $item): bool => $item['severity'] === 'Warning' && str_contains($item['message'], 'Preferred Work')))->toBeTrue();
});

it('shows exact Preferred Work in both pickers without boosting Optional ranking', function () {
    [$admin, , $shift, , $doctors] = editingFixture();
    $preferred = $doctors[0];
    $other = $doctors[1];
    DoctorRequest::create(['doctor_id' => $preferred->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $shift->shift_date, 'shift_type_id' => $shift->shift_type_id]);

    $this->actingAs($admin);
    $main = $this->getJson(optionsUrl($shift, 'main', 1))->assertOk()->json('options');
    $optional = $this->getJson(optionsUrl($shift, 'optional', 1))->assertOk()->json('options');
    expect(collect($main)->firstWhere('id', $preferred->id)['preferred_work'])->toBeTrue()
        ->and(collect($optional)->firstWhere('id', $preferred->id)['preferred_work'])->toBeTrue()
        ->and(array_search($preferred->id, array_column($main, 'id'), true) < array_search($other->id, array_column($main, 'id'), true))->toBeTrue()
        ->and(array_search($other->id, array_column($optional, 'id'), true) < array_search($preferred->id, array_column($optional, 'id'), true))->toBeTrue();
});

it('does not warn about Preferred Work already impossible from an unrelated Day-Off', function () {
    [$admin, , $tuesdayDay, , $doctors] = editingFixture();
    $doctor = $doctors[0];
    $mondayNight = RosterShift::query()->whereDate('shift_date', '2026-10-05')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_night'))->firstOrFail();
    Doctor::query()->where('id', '!=', $doctor->id)->update(['is_active' => false]);
    syncRosterParticipationToActiveDoctors();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $tuesdayDay->shift_date, 'shift_type_id' => $tuesdayDay->shift_type_id]);
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => $tuesdayDay->shift_date, 'shift_type_id' => $tuesdayDay->shift_type_id]);

    $this->actingAs($admin)->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($mondayNight, 'main', 1), 'doctor_id' => $doctor->id])
        ->assertOk()->assertJsonPath('status', 'saved');
});

it('does not warn about Preferred Work already fulfilled by Main', function () {
    [$admin, , $tuesdayDay, , $doctors] = editingFixture();
    $doctor = $doctors[0];
    $mondayDay = RosterShift::query()->whereDate('shift_date', '2026-10-05')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_day'))->firstOrFail();
    Doctor::query()->where('id', '!=', $doctor->id)->update(['is_active' => false]);
    syncRosterParticipationToActiveDoctors();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => $tuesdayDay->shift_date, 'shift_type_id' => $tuesdayDay->shift_type_id]);
    RosterAssignment::create(['roster_shift_id' => $tuesdayDay->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    $this->actingAs($admin)->postJson(editingUrl('edit'), ['operation' => 'replace', ...editPayload($mondayDay, 'main', 1), 'doctor_id' => $doctor->id])
        ->assertOk()->assertJsonPath('status', 'saved');
});
