<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorRequest;
use App\Models\RosterAssignment;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorMonthlyParticipationService;
use App\Services\RosterDraftContext;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;

function monthlyShiftRestrictionUrl(string $name, array $extra = []): string
{
    return route($name, ['year' => 2026, 'month' => 10, ...$extra]);
}

function prepareMonthlyShiftRestrictionFixture(): array
{
    test()->travelTo('2026-09-01 09:00:00');
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();

    return [$admin, $roster, $doctor];
}

it('saves a doctor monthly restriction set without carrying it into another month', function () {
    [$admin, , $doctor] = prepareMonthlyShiftRestrictionFixture();
    $evening = ShiftType::query()->where('code', 'weekday_evening')->firstOrFail();
    $night = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $payload = ['doctor_id' => $doctor->id, 'shift_type_ids' => [$evening->id, $night->id]];

    $this->actingAs($admin)->post(monthlyShiftRestrictionUrl('monthly-shift-restrictions.store'), $payload)->assertRedirect();
    $this->post(monthlyShiftRestrictionUrl('monthly-shift-restrictions.store'), $payload)->assertRedirect();

    expect(DoctorMonthlyShiftRestriction::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 10)->count())->toBe(2)
        ->and(DoctorMonthlyShiftRestriction::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 11)->count())->toBe(0);
    expect(fn () => DoctorMonthlyShiftRestriction::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'shift_type_id' => $evening->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rejects inactive or missing doctors and invalid shift types', function () {
    [$admin] = prepareMonthlyShiftRestrictionFixture();
    $inactiveDoctor = Doctor::query()->where('is_active', false)->firstOrFail();

    $this->actingAs($admin)->post(monthlyShiftRestrictionUrl('monthly-shift-restrictions.store'), ['doctor_id' => $inactiveDoctor->id, 'shift_type_ids' => []])
        ->assertSessionHasErrors('doctor_id');
    $this->post(monthlyShiftRestrictionUrl('monthly-shift-restrictions.store'), ['doctor_id' => 99999, 'shift_type_ids' => []])
        ->assertSessionHasErrors('doctor_id');
    $this->post(monthlyShiftRestrictionUrl('monthly-shift-restrictions.store'), ['doctor_id' => Doctor::query()->where('is_active', true)->value('id'), 'shift_type_ids' => [99999]])
        ->assertSessionHasErrors('shift_type_ids.0');

    $existingRestriction = DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $inactiveDoctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->value('id'),
    ]);
    $this->put(monthlyShiftRestrictionUrl('monthly-shift-restrictions.update', ['doctorMonthlyShiftRestriction' => $existingRestriction->id]), [
        'doctor_id' => $inactiveDoctor->id,
        'shift_type_ids' => [
            $existingRestriction->shift_type_id,
            ShiftType::query()->where('code', 'weekday_evening')->value('id'),
        ],
    ])->assertSessionHasErrors('doctor_id');

    $this->assertDatabaseCount('doctor_monthly_shift_restrictions', 1);
    $this->assertDatabaseHas('doctor_monthly_shift_restrictions', ['id' => $existingRestriction->id]);
    $this->get(monthlyShiftRestrictionUrl('monthly-setup.show'))->assertInertia(fn (Assert $page) => $page
        ->has('shiftRestrictions', 1)
        ->where('shiftRestrictions.0.doctor.is_active', false)
        ->where('doctors', fn ($doctors): bool => $doctors->doesntContain('id', $inactiveDoctor->id)));
});

it('allows editing and removing the restriction set and rejects cross-month resource URLs', function () {
    [$admin, , $doctor] = prepareMonthlyShiftRestrictionFixture();
    $evening = ShiftType::query()->where('code', 'weekday_evening')->firstOrFail();
    $night = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $restriction = DoctorMonthlyShiftRestriction::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'shift_type_id' => $evening->id]);

    $this->actingAs($admin)->put(monthlyShiftRestrictionUrl('monthly-shift-restrictions.update', ['doctorMonthlyShiftRestriction' => $restriction->id]), ['doctor_id' => $doctor->id, 'shift_type_ids' => [$night->id]])->assertRedirect();
    expect(DoctorMonthlyShiftRestriction::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 10)->pluck('shift_type_id')->all())->toBe([$night->id]);
    $updatedRestriction = DoctorMonthlyShiftRestriction::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 10)->where('shift_type_id', $night->id)->firstOrFail();

    $this->delete(route('monthly-shift-restrictions.destroy', ['year' => 2026, 'month' => 11, 'doctorMonthlyShiftRestriction' => $updatedRestriction->id]))->assertRedirect(route('dashboard'));
    $this->assertDatabaseHas('doctor_monthly_shift_restrictions', ['id' => $updatedRestriction->id]);
    $this->delete(monthlyShiftRestrictionUrl('monthly-shift-restrictions.destroy', ['doctorMonthlyShiftRestriction' => $updatedRestriction->id]))->assertRedirect();
    $this->assertDatabaseCount('doctor_monthly_shift_restrictions', 0);
});

it('requires authentication and locks mutations while keeping Final restrictions visible', function () {
    [$admin, $roster, $doctor] = prepareMonthlyShiftRestrictionFixture();
    $night = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $restriction = DoctorMonthlyShiftRestriction::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'shift_type_id' => $night->id]);
    $payload = ['doctor_id' => $doctor->id, 'shift_type_ids' => [$night->id]];

    $this->post(monthlyShiftRestrictionUrl('monthly-shift-restrictions.store'), $payload)->assertRedirect(route('login'));
    $roster->update(['status' => RosterStatus::Final]);
    $this->actingAs($admin)->put(monthlyShiftRestrictionUrl('monthly-shift-restrictions.update', ['doctorMonthlyShiftRestriction' => $restriction->id]), $payload)->assertRedirect();
    $this->post(monthlyShiftRestrictionUrl('monthly-shift-restrictions.store'), ['doctor_id' => $doctor->id, 'shift_type_ids' => []])->assertRedirect();
    $this->delete(monthlyShiftRestrictionUrl('monthly-shift-restrictions.destroy', ['doctorMonthlyShiftRestriction' => $restriction->id]))->assertRedirect();
    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->has('shiftRestrictions', 1)->where('shiftRestrictions.0.shift_type.code', 'weekday_night'));
    $this->assertDatabaseHas('doctor_monthly_shift_restrictions', ['id' => $restriction->id]);

    $this->post(route('rosters.reopen', ['year' => 2026, 'month' => 10]))
        ->assertRedirect(route('rosters.show', ['year' => 2026, 'month' => 10]));
    $this->put(monthlyShiftRestrictionUrl('monthly-shift-restrictions.update', ['doctorMonthlyShiftRestriction' => $restriction->id]), ['doctor_id' => $doctor->id, 'shift_type_ids' => []])->assertRedirect();
    $this->assertDatabaseCount('doctor_monthly_shift_restrictions', 0);
});

it('enforces monthly restrictions through shared roster eligibility for every assignment role', function () {
    [, $roster, $doctor] = prepareMonthlyShiftRestrictionFixture();
    $evening = ShiftType::query()->where('code', 'weekday_evening')->firstOrFail();
    $day = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    DoctorMonthlyShiftRestriction::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'shift_type_id' => $evening->id]);
    app(DoctorMonthlyParticipationService::class)->assertRosterSnapshotIntegrity($roster);
    $context = app(RosterDraftContext::class);
    $context->load($roster);
    $eveningShift = $roster->shifts()->where('shift_type_id', $evening->id)->firstOrFail();
    $dayShift = $roster->shifts()->where('shift_type_id', $day->id)->firstOrFail();

    expect($context->hardReasons($doctor->id, $eveningShift, []))->toContain('Dr Nuwan (MOIC) is restricted from this shift type this month.')
        ->and($context->hardReasons($doctor->id, $dayShift, []))->toBe([]);

    $admin = User::factory()->create();
    $doctorId = $doctor->id;
    test()->actingAs($admin);
    foreach (['main', 'optional'] as $role) {
        $optionsUrl = route('rosters.assignment-options', ['year' => 2026, 'month' => 10]).'?'.http_build_query([
            'shift_id' => $dayShift->id, 'role' => $role, 'slot_number' => 1,
            'expected_assignment_id' => '', 'expected_doctor_id' => '',
        ]);
        $options = test()->getJson($optionsUrl)->assertOk()->json('options');
        expect(collect($options)->firstWhere('id', $doctorId)['eligible'])->toBeTrue();
    }
    $eveningOptions = test()->getJson(route('rosters.assignment-options', ['year' => 2026, 'month' => 10]).'?'.http_build_query([
        'shift_id' => $eveningShift->id, 'role' => 'main', 'slot_number' => 1,
        'expected_assignment_id' => '', 'expected_doctor_id' => '',
    ]))->assertOk()->json('options');
    expect(collect($eveningOptions)->firstWhere('id', $doctorId)['reasons'])->toContain('Dr Nuwan (MOIC) is restricted from this shift type this month.');
    test()->postJson(route('rosters.assignments.edit', ['year' => 2026, 'month' => 10]), [
        'operation' => 'replace', 'shift_id' => $eveningShift->id, 'role' => 'main', 'slot_number' => 1,
        'expected_assignment_id' => null, 'expected_doctor_id' => null, 'doctor_id' => $doctorId, 'confirm_soft_override' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('edit');
});

it('keeps conflicting Preferred Work visible while excluding it from candidate ranking', function () {
    [$admin, $roster, $doctor] = prepareMonthlyShiftRestrictionFixture();
    $evening = ShiftType::query()->where('code', 'weekday_evening')->firstOrFail();
    $day = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $preferredShift = $roster->shifts()->whereDate('shift_date', '2026-10-06')->where('shift_type_id', $evening->id)->firstOrFail();
    $candidateShift = $roster->shifts()->whereDate('shift_date', '2026-10-06')->where('shift_type_id', $day->id)->firstOrFail();
    DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => $preferredShift->shift_date,
        'shift_type_id' => $evening->id,
    ]);
    DoctorMonthlyShiftRestriction::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => $evening->id,
    ]);

    $context = app(RosterDraftContext::class);
    $context->load($roster);
    $dimensions = $context->dimensions($doctor->id, $candidateShift, RosterAssignmentRole::Main, []);

    expect($dimensions[1])->toBe(0)
        ->and($context->prefers($doctor->id, $preferredShift))->toBeFalse()
        ->and(DoctorRequest::query()->where('doctor_id', $doctor->id)->where('request_type', DoctorRequestType::PreferredWork->value)->count())->toBe(1);

    $this->actingAs($admin)->get(monthlyShiftRestrictionUrl('monthly-setup.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('preferredWorkRequests', 1)
            ->where('warnings.0.type', 'restricted_preferred_work'));
    expect(collect(app(RosterDraftValidationService::class)->validate($roster))
        ->contains(fn (array $item): bool => $item['code'] === 'preferred_work_unfulfilled'))->toBeTrue();
});

it('never generates restricted shift assignments and reports the conflict on existing drafts', function () {
    [$admin, $roster, $doctor] = prepareMonthlyShiftRestrictionFixture();
    $restricted = ShiftType::query()->whereIn('code', ['weekday_evening', 'weekday_night', 'weekend_night'])->get();
    foreach ($restricted as $shiftType) {
        DoctorMonthlyShiftRestriction::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'shift_type_id' => $shiftType->id]);
    }
    $forbiddenShift = $roster->shifts()->where('shift_type_id', $restricted->firstWhere('code', 'weekday_night')->id)->firstOrFail();
    $assignment = $forbiddenShift->assignments()->create(['doctor_id' => $doctor->id, 'role' => 'main', 'slot_number' => 1]);
    $context = app(RosterDraftContext::class);
    $context->load($roster);
    expect($context->hardReasons($doctor->id, $forbiddenShift, []))->toContain('Dr Nuwan (MOIC) is restricted from this shift type this month.');
    expect(collect(app(RosterDraftValidationService::class)->validate($roster))->contains(fn (array $item): bool => $item['code'] === 'hard_conflict' && str_contains($item['message'], 'restricted from this shift type')))->toBeTrue();
    $assignment->delete();

    test()->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    $nuwanForbiddenAssignments = RosterAssignment::query()->where('doctor_id', $doctor->id)
        ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id)->whereIn('shift_type_id', $restricted->pluck('id')))->count();
    expect($nuwanForbiddenAssignments)->toBe(0);

    $november = app(RosterStructureService::class)->create(2026, 11, $admin);
    $novemberDay = $november->shifts()->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_evening'))->firstOrFail();
    $context->load($november);
    expect($context->hardReasons($doctor->id, $novemberDay, []))->toBe([]);
});
