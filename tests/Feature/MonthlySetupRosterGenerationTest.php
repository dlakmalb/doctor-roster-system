<?php

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\User;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function setupRosterGeneration(int $month = 10): User
{
    test()->travelTo(CarbonImmutable::parse($month === 10 ? '2026-09-01 09:00:00' : '2026-10-04 09:00:00'));
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();

    return User::factory()->create();
}

function monthlySetupGenerateUrl(int $year = 2026, int $month = 10): string
{
    return route('monthly-setup.generate-roster', ['year' => $year, 'month' => $month]);
}

it('generates a new Draft roster directly from Monthly Setup', function () {
    $admin = setupRosterGeneration();
    $this->actingAs($admin)->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')
            ->where('rosterStatus', 'not_started')
            ->where('rosterAction', 'generate'));

    $this->post(monthlySetupGenerateUrl())
        ->assertRedirectToRoute('rosters.show', ['year' => 2026, 'month' => 10]);

    $roster = Roster::query()->sole();
    expect($roster->status)->toBe(RosterStatus::Draft)
        ->and($roster->last_generated_at)->not->toBeNull();
    $this->assertDatabaseCount('roster_shifts', 84);
    expect(RosterAssignment::query()->count())->toBeGreaterThan(0);
});

it('generates an existing empty Draft without duplicating its structure', function () {
    $admin = setupRosterGeneration();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $shiftIds = $roster->shifts()->orderBy('id')->pluck('id')->all();

    $this->actingAs($admin)->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')
            ->where('rosterStatus', 'draft')
            ->where('rosterAction', 'generate'));

    $this->actingAs($admin)->post(monthlySetupGenerateUrl())
        ->assertRedirectToRoute('rosters.show', ['year' => 2026, 'month' => 10]);

    expect(Roster::query()->count())->toBe(1)
        ->and($roster->fresh()->last_generated_at)->not->toBeNull()
        ->and($roster->shifts()->orderBy('id')->pluck('id')->all())->toBe($shiftIds)
        ->and(RosterAssignment::query()->count())->toBeGreaterThan(0);
    $this->assertDatabaseCount('roster_shifts', 84);
});

it('shows and preserves an already generated Draft on repeated generation requests', function () {
    $admin = setupRosterGeneration();
    $this->actingAs($admin)->post(monthlySetupGenerateUrl())->assertRedirect();
    $roster = Roster::query()->sole();
    $generatedAt = $roster->last_generated_at?->toDateTimeString();
    $assignments = RosterAssignment::query()->orderBy('id')->get()->toArray();

    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')
            ->where('rosterAction', 'view_draft'));
    $this->post(monthlySetupGenerateUrl())->assertRedirectToRoute('rosters.show', ['year' => 2026, 'month' => 10]);

    expect($roster->fresh()->last_generated_at?->toDateTimeString())->toBe($generatedAt)
        ->and(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($assignments);
});

it('shows and preserves a Final roster when the generation action is invoked directly', function () {
    $admin = setupRosterGeneration();
    $this->actingAs($admin)->post(monthlySetupGenerateUrl())->assertRedirect();
    $roster = Roster::query()->sole();
    $roster->update(['status' => RosterStatus::Final, 'finalized_at' => now()]);
    $finalizedAt = $roster->fresh()->finalized_at?->toDateTimeString();
    $generatedAt = $roster->fresh()->last_generated_at?->toDateTimeString();
    $assignments = RosterAssignment::query()->orderBy('id')->get()->toArray();

    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')
            ->where('rosterStatus', 'final')
            ->where('rosterAction', 'view_final'));
    $this->post(monthlySetupGenerateUrl())->assertRedirectToRoute('rosters.show', ['year' => 2026, 'month' => 10]);

    expect($roster->fresh()->status)->toBe(RosterStatus::Final)
        ->and($roster->fresh()->finalized_at?->toDateTimeString())->toBe($finalizedAt)
        ->and($roster->fresh()->last_generated_at?->toDateTimeString())->toBe($generatedAt)
        ->and(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($assignments);
});

it('preserves manual assignments when generating an ungenerated Draft', function () {
    $admin = setupRosterGeneration();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $shift = RosterShift::query()->where('roster_id', $roster->id)->orderBy('shift_date')->firstOrFail();
    $manualAssignment = RosterAssignment::create([
        'roster_shift_id' => $shift->id,
        'doctor_id' => Doctor::query()->firstOrFail()->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);

    $this->actingAs($admin)->post(monthlySetupGenerateUrl())->assertRedirect();

    expect($roster->fresh()->last_generated_at)->not->toBeNull()
        ->and(RosterAssignment::query()->count())->toBeGreaterThan(1)
        ->and($manualAssignment->fresh()?->doctor_id)->toBe($manualAssignment->doctor_id);
});

it('blocks generation when the previous month remains Draft without partial assignments', function () {
    $admin = setupRosterGeneration(month: 11);
    $previous = app(RosterStructureService::class)->create(2026, 10, $admin);

    $this->actingAs($admin)->from(route('monthly-setup.show', ['year' => 2026, 'month' => 11]))
        ->post(monthlySetupGenerateUrl(month: 11))
        ->assertRedirect(route('monthly-setup.show', ['year' => 2026, 'month' => 11]))
        ->assertSessionHasErrors(['roster' => 'Finalize October 2026 before generating November 2026.']);

    $roster = Roster::query()->where('year', 2026)->where('month', 11)->firstOrFail();
    expect($previous->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->status)->toBe(RosterStatus::Draft)
        ->and($roster->last_generated_at)->toBeNull()
        ->and($roster->shifts()->count())->toBe(81)
        ->and(RosterAssignment::query()->count())->toBe(0);
});

it('leaves an empty Draft and reports a useful error when generation throws', function () {
    $admin = setupRosterGeneration();
    $generator = Mockery::mock(RosterAssignmentGenerator::class);
    $generator->shouldReceive('generate')->once()->andThrow(new RuntimeException('Unexpected failure.'));
    app()->instance(RosterAssignmentGenerator::class, $generator);

    $this->actingAs($admin)->from(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->post(monthlySetupGenerateUrl())
        ->assertRedirect(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertSessionHasErrors(['roster' => 'Roster generation failed. No assignments were saved. Please try again.']);

    $roster = Roster::query()->sole();
    expect($roster->status)->toBe(RosterStatus::Draft)
        ->and($roster->last_generated_at)->toBeNull()
        ->and($roster->shifts()->count())->toBe(84)
        ->and(RosterAssignment::query()->count())->toBe(0);
});
