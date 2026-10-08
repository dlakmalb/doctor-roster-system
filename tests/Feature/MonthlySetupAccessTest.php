<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RosterPlanningPeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function setupAccessFixture(string $now): array
{
    test()->travelTo(CarbonImmutable::parse($now));
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);

    $doctors = Doctor::query()->orderByDesc('is_active')->orderBy('short_code')->get();

    return [User::factory()->create(), $doctors, ShiftType::query()->get()->keyBy('code')];
}

function setupAccessUrl(string $name, int $year, int $month, array $extra = []): string
{
    return route($name, ['year' => $year, 'month' => $month, ...$extra]);
}

function setupAccessBaselinePayload($doctors): array
{
    return ['doctors' => $doctors->map(fn (Doctor $doctor): array => [
        'doctor_id' => $doctor->id,
        'participation_status' => $doctor->is_active ? 'participating' : 'not_part_of_team',
        'actual_hours' => '0',
        'actual_night_duty_count' => 0,
        'optional_assignment_count' => 0,
        'worked_final_weekend' => false,
        'most_recent_night_shift_at' => null,
    ])->all()];
}

function saveSetupBaseline(User $admin, int $year, int $month, $doctors): void
{
    test()->actingAs($admin)
        ->post(setupAccessUrl('initial-workload.save', $year, $month), setupAccessBaselinePayload($doctors))
        ->assertRedirect();
}

it('redirects first-time Monthly Setup to current-month Initial Setup and blocks other periods', function () {
    [$admin] = setupAccessFixture('2026-10-04 09:00:00');

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialSetup.required', true)
            ->where('initialSetup.month.year', 2026)
            ->where('initialSetup.month.month', 10)
            ->where('initialSetup.month.label', 'October 2026')
            ->where('primaryMonth.label', 'November 2026'));

    $this->get(setupAccessUrl('monthly-setup.show', 2026, 11))
        ->assertRedirect(route('initial-workload.show', ['year' => 2026, 'month' => 10]))
        ->assertSessionHas('status', 'Complete Initial Setup for October 2026 before managing November 2026 Monthly Setup.');
    $this->get(route('initial-workload.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.status', 'Complete Initial Setup for October 2026 before managing November 2026 Monthly Setup.'));

    foreach ([9, 10, 12] as $month) {
        $this->get(setupAccessUrl('monthly-setup.show', 2026, $month))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Monthly Setup is currently available for November 2026.');
    }
});

it('allows only November Monthly Setup after the October ManualInitial baseline is complete', function () {
    [$admin, $doctors] = setupAccessFixture('2026-10-04 09:00:00');
    saveSetupBaseline($admin, 2026, 10, $doctors);

    $this->actingAs($admin)->get(setupAccessUrl('monthly-setup.show', 2026, 11))
        ->assertInertia(fn (Assert $page) => $page
            ->component('monthly-setup')
            ->where('month.label', 'November 2026'));
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialSetup.required', false)
            ->where('primaryMonth.label', 'November 2026'));

    foreach ([9, 10, 12] as $month) {
        $this->get(setupAccessUrl('monthly-setup.show', 2026, $month))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Monthly Setup is currently available for November 2026.');
    }
});

it('keeps Monthly Setup available when the current roster is Draft without allowing generation', function () {
    [$admin, $doctors] = setupAccessFixture('2026-10-04 09:00:00');
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);

    $this->actingAs($admin)->get(setupAccessUrl('monthly-setup.show', 2026, 11))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')->where('month.label', 'November 2026'));

    $this->post(setupAccessUrl('doctor-requests.store', 2026, 11), [
        'doctor_id' => $doctors[0]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-11-15',
    ])->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $request = DoctorRequest::query()->where('doctor_id', $doctors[0]->id)->sole();

    $this->post(setupAccessUrl('monthly-exclusions.store', 2026, 11), ['doctor_id' => $doctors[1]->id])
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $this->assertDatabaseHas('doctor_monthly_exclusions', ['doctor_id' => $doctors[1]->id, 'year' => 2026, 'month' => 11]);

    $this->post(setupAccessUrl('rosters.store', 2026, 11))
        ->assertRedirect(route('rosters.show', ['year' => 2026, 'month' => 11]));
    $november = Roster::query()->where('year', 2026)->where('month', 11)->sole();
    expect($november->status)->toBe(RosterStatus::Draft);

    $generationMessage = 'Finalize October 2026 before generating November 2026.';
    $this->post(setupAccessUrl('rosters.generate', 2026, 11))->assertSessionHasErrors(['roster' => $generationMessage]);
    $this->post(setupAccessUrl('rosters.regenerate', 2026, 11))->assertSessionHasErrors(['roster' => $generationMessage]);

    $october->update(['status' => RosterStatus::Final]);
    $this->post(setupAccessUrl('rosters.reopen', 2026, 10))->assertRedirect(route('rosters.show', ['year' => 2026, 'month' => 10]));
    expect($october->fresh()->status)->toBe(RosterStatus::Draft);

    $this->get(setupAccessUrl('monthly-setup.show', 2026, 11))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')->where('month.label', 'November 2026'));
    $this->put(setupAccessUrl('doctor-requests.update', 2026, 11, ['doctorRequest' => $request]), [
        'doctor_id' => $doctors[0]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-11-16',
    ])->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $this->assertDatabaseHas('doctor_requests', ['id' => $request->id, 'request_date' => '2026-11-16 00:00:00']);
    $this->post(setupAccessUrl('rosters.generate', 2026, 11))->assertSessionHasErrors(['roster' => $generationMessage]);
});

it('keeps November valid after it becomes Final and leaves historical roster and Actual Work routes open', function () {
    [$admin, $doctors] = setupAccessFixture('2026-10-04 09:00:00');
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    snapshotRosterParticipation($october, $doctors);

    $this->actingAs($admin)->get(setupAccessUrl('monthly-setup.show', 2026, 11))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')->where('month.label', 'November 2026'));
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    snapshotRosterParticipation($november, $doctors);
    $this->get(setupAccessUrl('monthly-setup.show', 2026, 11))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')->where('rosterStatus', 'final'));
    $this->get(setupAccessUrl('monthly-setup.show', 2026, 12))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Monthly Setup is currently available for November 2026.');

    $this->get(setupAccessUrl('rosters.show', 2026, 10))->assertInertia(fn (Assert $page) => $page->component('roster'));
    $this->get(setupAccessUrl('rosters.actual-work.show', 2026, 10))->assertInertia(fn (Assert $page) => $page->component('actual-work-review'));
    $this->assertModelExists($october);
});

it('guards Monthly Setup mutations, validates resource periods, and preserves valid November requests', function () {
    [$admin, $doctors, $shiftTypes] = setupAccessFixture('2026-10-04 09:00:00');
    saveSetupBaseline($admin, 2026, 10, $doctors);
    $legacyRequest = DoctorRequest::create([
        'doctor_id' => $doctors[0]->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-10-20',
    ]);
    $legacyExclusion = DoctorMonthlyExclusion::create([
        'doctor_id' => $doctors[1]->id,
        'year' => 2026,
        'month' => 10,
    ]);

    $this->actingAs($admin)->post(setupAccessUrl('doctor-requests.store', 2026, 10), [
        'doctor_id' => $doctors[2]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-15',
    ])->assertRedirect(route('dashboard'))->assertSessionHas('status', 'Monthly Setup is currently available for November 2026.');
    $this->post(setupAccessUrl('doctor-requests.store', 2026, 12), [
        'doctor_id' => $doctors[2]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-12-15',
    ])->assertRedirect(route('dashboard'));
    $this->post(setupAccessUrl('monthly-exclusions.store', 2026, 9), ['doctor_id' => $doctors[2]->id])
        ->assertRedirect(route('dashboard'));

    $this->post(setupAccessUrl('doctor-requests.store', 2026, 11), [
        'doctor_id' => $doctors[2]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-10-31',
    ])->assertSessionHasErrors('request_date');
    $this->post(setupAccessUrl('doctor-requests.store', 2026, 11), [
        'doctor_id' => $doctors[2]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-12-01',
    ])->assertSessionHasErrors('request_date');

    $this->post(setupAccessUrl('doctor-requests.store', 2026, 11), [
        'doctor_id' => $doctors[2]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-11-15',
    ])->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $preferredWork = $this->post(setupAccessUrl('doctor-requests.store', 2026, 11), [
        'doctor_id' => $doctors[3]->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-11-16',
        'shift_type_id' => $shiftTypes['weekday_day']->id,
    ])->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $request = DoctorRequest::query()->where('doctor_id', $doctors[3]->id)->firstOrFail();

    $this->put(setupAccessUrl('doctor-requests.update', 2026, 11, ['doctorRequest' => $legacyRequest]), [
        'doctor_id' => $doctors[0]->id,
        'request_type' => 'day_off',
        'request_date' => '2026-11-20',
    ])->assertNotFound();
    $this->delete(setupAccessUrl('doctor-requests.destroy', 2026, 11, ['doctorRequest' => $legacyRequest]))->assertNotFound();
    $this->delete(setupAccessUrl('monthly-exclusions.destroy', 2026, 11, ['doctorMonthlyExclusion' => $legacyExclusion]))->assertNotFound();
    $this->assertModelExists($legacyRequest);
    $this->assertModelExists($legacyExclusion);

    $this->put(setupAccessUrl('doctor-requests.update', 2026, 11, ['doctorRequest' => $request]), [
        'doctor_id' => $doctors[3]->id,
        'request_type' => 'preferred_work',
        'request_date' => '2026-11-17',
        'shift_type_id' => $shiftTypes['weekday_day']->id,
    ])->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $this->assertDatabaseHas('doctor_requests', ['id' => $request->id, 'request_date' => '2026-11-17 00:00:00']);
    $this->delete(setupAccessUrl('doctor-requests.destroy', 2026, 11, ['doctorRequest' => $request]))
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);

    $this->post(setupAccessUrl('monthly-exclusions.store', 2026, 11), ['doctor_id' => $doctors[4]->id])
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $novemberExclusion = DoctorMonthlyExclusion::query()->where('year', 2026)->where('month', 11)->sole();
    $this->delete(setupAccessUrl('monthly-exclusions.destroy', 2026, 11, ['doctorMonthlyExclusion' => $novemberExclusion]))
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 11]);
    $this->assertModelMissing($novemberExclusion);
    $this->assertDatabaseCount('doctor_requests', 2);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 1);
    expect($preferredWork->headers->get('Location'))->toContain('/monthly-setup/2026/11');
});

it('allows January 2027 planning after December Initial Setup and protects Draft creation periods', function () {
    [$admin, $doctors] = setupAccessFixture('2026-12-15 09:00:00');
    saveSetupBaseline($admin, 2026, 12, $doctors);

    $this->actingAs($admin)->get(setupAccessUrl('monthly-setup.show', 2027, 1))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')->where('month.label', 'January 2027'));
    $this->post(setupAccessUrl('rosters.store', 2027, 1))->assertRedirect(route('rosters.show', ['year' => 2027, 'month' => 1]));
    $this->assertDatabaseHas('rosters', ['year' => 2027, 'month' => 1, 'status' => RosterStatus::Draft->value]);

    foreach ([[2026, 12], [2027, 2]] as [$year, $month]) {
        $this->get(setupAccessUrl('monthly-setup.show', $year, $month))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Monthly Setup is currently available for January 2027.');
        $this->post(setupAccessUrl('rosters.store', $year, $month))
            ->assertRedirect(route('dashboard'));
    }
    expect(app(RosterPlanningPeriodService::class)->operationalMonth()->format('Y-m'))->toBe('2026-12')
        ->and(app(RosterPlanningPeriodService::class)->planningMonth()->format('Y-m'))->toBe('2027-01');
    $this->assertDatabaseCount('rosters', 1);
});
