<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorMonthlyParticipationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function monthlySetupFinalFixture(bool $final = true, bool $actualWorkStarted = false): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-04 09:00:00'));
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);

    $admin = User::factory()->create();
    $doctors = Doctor::query()->orderByDesc('is_active')->orderBy('short_code')->get();
    $activeDoctors = $doctors->where('is_active', true)->values();
    test()->actingAs($admin)->post(route('initial-workload.save', ['year' => 2026, 'month' => 10]), [
        'doctors' => $doctors->map(fn (Doctor $doctor): array => [
            'doctor_id' => $doctor->id,
            'participation_status' => $doctor->is_active ? 'participating' : 'not_part_of_team',
            'actual_hours' => '0',
            'actual_night_duty_count' => 0,
            'optional_assignment_count' => 0,
            'worked_final_weekend' => false,
            'most_recent_night_shift_at' => null,
        ])->all(),
    ])->assertRedirect();

    $offRequest = DoctorRequest::create([
        'doctor_id' => $doctors[1]->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-11-10',
        'note' => 'Clinic appointment',
    ]);
    $preferredWork = DoctorRequest::create([
        'doctor_id' => $doctors[2]->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => '2026-11-02',
        'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->value('id'),
        'note' => 'Preferred day shift',
    ]);
    $exclusion = DoctorMonthlyExclusion::create([
        'doctor_id' => $doctors[3]->id,
        'year' => 2026,
        'month' => 11,
        'note' => 'On leave',
    ]);

    $weekdayDay = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $weekdayDay->update(['main_count' => 2, 'optional_count' => 0]);
    $roster = Roster::create([
        'year' => 2026,
        'month' => 11,
        'status' => RosterStatus::Draft,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
        'last_generated_at' => now(),
    ]);
    app(DoctorMonthlyParticipationService::class)->snapshotRoster($roster, $doctors->where('is_active', true));
    $shift = RosterShift::create([
        'roster_id' => $roster->id,
        'shift_date' => '2026-11-02',
        'shift_type_id' => $weekdayDay->id,
    ]);
    RosterAssignment::create([
        'roster_shift_id' => $shift->id,
        'doctor_id' => $activeDoctors[0]->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);
    RosterAssignment::create([
        'roster_shift_id' => $shift->id,
        'doctor_id' => $activeDoctors[2]->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 2,
    ]);

    if ($final) {
        $roster->update([
            'status' => RosterStatus::Final,
            'finalized_at' => now(),
            'finalized_by' => $admin->id,
            'actual_work_confirmed_at' => $actualWorkStarted ? now() : null,
            'actual_work_confirmed_by' => $actualWorkStarted ? $admin->id : null,
        ]);
    }

    return [$admin, $doctors, $roster->fresh(), $shift, $offRequest, $preferredWork, $exclusion];
}

function monthlySetupFinalUrl(string $name, array $extra = []): string
{
    return route($name, ['year' => 2026, 'month' => 11, ...$extra]);
}

function monthlySetupFinalRequestPayload(int $doctorId, string $date, string $type = 'day_off', ?int $shiftTypeId = null): array
{
    return [
        'doctor_id' => $doctorId,
        'request_type' => $type,
        'request_date' => $date,
        'shift_type_id' => $shiftTypeId,
        'note' => 'Submitted while locked',
    ];
}

function monthlySetupRosterState(Roster $roster): array
{
    $roster->refresh();

    return [
        'status' => $roster->status->value,
        'finalized_at' => $roster->finalized_at?->toDateTimeString(),
        'last_generated_at' => $roster->last_generated_at?->toDateTimeString(),
        'actual_work_confirmed_at' => $roster->actual_work_confirmed_at?->toDateTimeString(),
        'updated_at' => $roster->updated_at?->toDateTimeString(),
    ];
}

it('renders Final Monthly Setup as a record of its requests and exclusions', function () {
    [$admin, , , , $offRequest, $preferredWork, $exclusion] = monthlySetupFinalFixture();

    $this->actingAs($admin)->get(monthlySetupFinalUrl('monthly-setup.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monthly-setup')
            ->where('month.label', 'November 2026')
            ->where('rosterStatus', 'final')
            ->where('dayOffRequests.0.id', $offRequest->id)
            ->where('dayOffRequests.0.note', 'Clinic appointment')
            ->where('preferredWorkRequests.0.id', $preferredWork->id)
            ->where('preferredWorkRequests.0.note', 'Preferred day shift')
            ->where('exclusions.0.id', $exclusion->id)
            ->where('exclusions.0.note', 'On leave')
            ->where('requests', fn (Collection $requests): bool => $requests->contains(fn (array $request): bool => $request['id'] === $offRequest->id)
                && $requests->contains(fn (array $request): bool => $request['id'] === $preferredWork->id)
                && $requests->contains(fn (array $request): bool => $request['id'] === $exclusion->id)));
});

it('rejects every Monthly Setup request and exclusion mutation while Final without changing roster data', function () {
    [$admin, $doctors, $roster, $shift, $offRequest, , $exclusion] = monthlySetupFinalFixture();
    $finalState = monthlySetupRosterState($roster);
    $assignments = RosterAssignment::query()->where('roster_shift_id', $shift->id)->orderBy('id')->get()->toArray();
    $requests = DoctorRequest::query()->orderBy('id')->get()->toArray();
    $exclusions = DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray();
    $workloads = DoctorMonthlyWorkload::query()->orderBy('doctor_id')->orderBy('year')->orderBy('month')->get()->toArray();
    $lockMessage = 'Monthly Setup is locked because the November 2026 roster is Final. Reopen the roster before making changes.';
    $this->actingAs($admin);

    $attempts = [
        fn () => $this->post(monthlySetupFinalUrl('doctor-requests.store'), monthlySetupFinalRequestPayload($doctors[4]->id, '2026-11-20')),
        fn () => $this->post(monthlySetupFinalUrl('doctor-requests.store'), monthlySetupFinalRequestPayload($doctors[5]->id, '2026-11-23', 'preferred_work', ShiftType::query()->where('code', 'weekday_day')->value('id'))),
        fn () => $this->put(monthlySetupFinalUrl('doctor-requests.update', ['doctorRequest' => $offRequest]), monthlySetupFinalRequestPayload($doctors[1]->id, '2026-11-12')),
        fn () => $this->delete(monthlySetupFinalUrl('doctor-requests.destroy', ['doctorRequest' => $offRequest])),
        fn () => $this->post(monthlySetupFinalUrl('monthly-exclusions.store'), ['doctor_id' => $doctors[6]->id, 'note' => 'New exclusion']),
        fn () => $this->delete(monthlySetupFinalUrl('monthly-exclusions.destroy', ['doctorMonthlyExclusion' => $exclusion])),
    ];

    foreach ($attempts as $attempt) {
        $attempt()->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11])
            ->assertSessionHas('status', $lockMessage);
        expect(monthlySetupRosterState($roster))->toBe($finalState)
            ->and(RosterAssignment::query()->where('roster_shift_id', $shift->id)->orderBy('id')->get()->toArray())->toBe($assignments)
            ->and(DoctorRequest::query()->orderBy('id')->get()->toArray())->toBe($requests)
            ->and(DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray())->toBe($exclusions)
            ->and(DoctorMonthlyWorkload::query()->orderBy('doctor_id')->orderBy('year')->orderBy('month')->get()->toArray())->toBe($workloads);
    }
});

it('restores Monthly Setup editing after Reopen and locks it again after re-finalizing', function () {
    [$admin, $doctors, $roster, , $offRequest] = monthlySetupFinalFixture();
    $this->actingAs($admin);
    $payload = monthlySetupFinalRequestPayload($doctors[4]->id, '2026-11-20');
    $this->post(monthlySetupFinalUrl('doctor-requests.store'), $payload)
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $this->assertDatabaseCount('doctor_requests', 2);

    $this->post(monthlySetupFinalUrl('rosters.reopen'))->assertRedirect(monthlySetupFinalUrl('rosters.show'));
    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);

    $this->put(monthlySetupFinalUrl('doctor-requests.update', ['doctorRequest' => $offRequest]), monthlySetupFinalRequestPayload($doctors[1]->id, '2026-11-12'))
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $this->post(monthlySetupFinalUrl('monthly-exclusions.store'), ['doctor_id' => $doctors[5]->id, 'note' => 'Added after reopen'])
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);

    $this->postJson(monthlySetupFinalUrl('rosters.finalize'))->assertJsonPath('status', 'finalized');
    expect($roster->fresh()->status)->toBe(RosterStatus::Final);
    $this->post(monthlySetupFinalUrl('doctor-requests.store'), monthlySetupFinalRequestPayload($doctors[6]->id, '2026-11-22'))
        ->assertSessionHas('status', 'Monthly Setup is locked because the November 2026 roster is Final. Reopen the roster before making changes.');
    $this->assertDatabaseCount('doctor_requests', 2);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 2);
});

it('keeps setup locked when Actual Work prevents Reopen and does not replace a Final roster on create', function () {
    [$admin, $doctors, $roster, $shift, , , $exclusion] = monthlySetupFinalFixture(actualWorkStarted: true);
    $this->actingAs($admin)->post(monthlySetupFinalUrl('rosters.reopen'))->assertSessionHasErrors('roster');
    $lockedState = monthlySetupRosterState($roster);
    $lockedRequests = DoctorRequest::query()->orderBy('id')->get()->toArray();
    $lockedExclusions = DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray();
    $lockedWorkloads = DoctorMonthlyWorkload::query()->orderBy('doctor_id')->orderBy('year')->orderBy('month')->get()->toArray();
    $this->post(monthlySetupFinalUrl('doctor-requests.store'), monthlySetupFinalRequestPayload($doctors[4]->id, '2026-11-20'))
        ->assertSessionHas('status', 'Monthly Setup is locked because the November 2026 roster is Final. Reopen the roster before making changes.');
    $this->post(monthlySetupFinalUrl('monthly-exclusions.store'), ['doctor_id' => $doctors[5]->id])
        ->assertSessionHas('status', 'Monthly Setup is locked because the November 2026 roster is Final. Reopen the roster before making changes.');

    expect($roster->fresh()->status)->toBe(RosterStatus::Final);
    expect(monthlySetupRosterState($roster))->toBe($lockedState)
        ->and(DoctorRequest::query()->orderBy('id')->get()->toArray())->toBe($lockedRequests)
        ->and(DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray())->toBe($lockedExclusions)
        ->and(DoctorMonthlyWorkload::query()->orderBy('doctor_id')->orderBy('year')->orderBy('month')->get()->toArray())->toBe($lockedWorkloads);
    $before = monthlySetupRosterState($roster);
    $assignments = RosterAssignment::query()->where('roster_shift_id', $shift->id)->get()->toArray();
    $requests = DoctorRequest::query()->orderBy('id')->get()->toArray();
    $exclusions = DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray();
    $workloads = DoctorMonthlyWorkload::query()->orderBy('doctor_id')->orderBy('year')->orderBy('month')->get()->toArray();
    $this->post(monthlySetupFinalUrl('rosters.store'))->assertRedirect(monthlySetupFinalUrl('rosters.show'));
    expect(monthlySetupRosterState($roster))->toBe($before)
        ->and(RosterAssignment::query()->where('roster_shift_id', $shift->id)->get()->toArray())->toBe($assignments)
        ->and(DoctorRequest::query()->orderBy('id')->get()->toArray())->toBe($requests)
        ->and(DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray())->toBe($exclusions)
        ->and(DoctorMonthlyWorkload::query()->orderBy('doctor_id')->orderBy('year')->orderBy('month')->get()->toArray())->toBe($workloads);
    $this->assertModelExists($exclusion);
    $this->assertDatabaseCount('rosters', 1);
    $this->assertDatabaseCount('doctor_requests', 2);
});

it('keeps a Final roster locked when setup resources from another month are submitted', function () {
    [$admin, $doctors] = monthlySetupFinalFixture();
    $otherMonthRequest = DoctorRequest::create([
        'doctor_id' => $doctors[7]->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-10-20',
    ]);
    $otherMonthExclusion = DoctorMonthlyExclusion::create([
        'doctor_id' => $doctors[8]->id,
        'year' => 2026,
        'month' => 10,
    ]);

    $this->actingAs($admin)
        ->put(monthlySetupFinalUrl('doctor-requests.update', ['doctorRequest' => $otherMonthRequest]), monthlySetupFinalRequestPayload($doctors[7]->id, '2026-11-12'))
        ->assertNotFound();
    $this->delete(monthlySetupFinalUrl('monthly-exclusions.destroy', ['doctorMonthlyExclusion' => $otherMonthExclusion]))
        ->assertNotFound();
    $this->assertModelExists($otherMonthRequest);
    $this->assertModelExists($otherMonthExclusion);
});
