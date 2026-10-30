<?php

use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\MonthlyShiftCapacity;
use App\Models\Roster;
use App\Models\RosterShiftCapacitySnapshot;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\MonthlyShiftCapacityService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function monthlyShiftCapacityManagementSetup(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();

    return [User::factory()->create(), Doctor::query()->where('is_active', true)->firstOrFail()];
}

function monthlyShiftCapacityManagementPayload(array $overrides = []): array
{
    $capacities = [
        ['shift_type' => 'weekday_day', 'main_count' => 3, 'optional_count' => 2],
        ['shift_type' => 'weekday_evening', 'main_count' => 2, 'optional_count' => 1],
        ['shift_type' => 'weekday_night', 'main_count' => 2, 'optional_count' => 0],
        ['shift_type' => 'weekend_day', 'main_count' => 2, 'optional_count' => 7],
        ['shift_type' => 'weekend_night', 'main_count' => 2, 'optional_count' => 0],
    ];

    foreach ($overrides as $index => $values) {
        $capacities[$index] = [...$capacities[$index], ...$values];
    }

    return ['capacities' => $capacities];
}

function monthlyShiftCapacityManagementUrl(string $routeName, array $extra = []): string
{
    return route($routeName, ['year' => 2026, 'month' => 10, ...$extra]);
}

it('shows the historical Draft roster instead of offering generation when no generation timestamp exists', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $roster = Roster::create([
        'year' => 2026,
        'month' => 10,
        'status' => RosterStatus::Draft,
        'created_by' => $admin->id,
    ]);
    expect($roster->last_generated_at)->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00:00'));

    $this->actingAs($admin)
        ->get(monthlyShiftCapacityManagementUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('isPlanningPeriod', false)
            ->where('rosterStatus', 'draft')
            ->where('rosterAction', 'view_draft'));
});

it('keeps roster generation available for a Draft in the current planning period', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    Roster::create([
        'year' => 2026,
        'month' => 10,
        'status' => RosterStatus::Draft,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->get(monthlyShiftCapacityManagementUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('isPlanningPeriod', true)
            ->where('rosterAction', 'generate'));
});

it('shows resolved global defaults for an unconfigured month without writing configuration records', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();

    $this->actingAs($admin)
        ->get(monthlyShiftCapacityManagementUrl('monthly-setup.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monthly-setup')
            ->has('capacityConfiguration', 5)
            ->where('capacityConfiguration', fn (Collection $capacities): bool => $capacities->pluck('shift_type.code')->all() === [
                'weekday_day',
                'weekday_evening',
                'weekday_night',
                'weekend_day',
                'weekend_night',
            ])
            ->where('capacityConfiguration.0.shift_type.code', 'weekday_day')
            ->where('capacityConfiguration.0.main_count', 4)
            ->where('capacityConfiguration.0.optional_count', 2)
            ->where('capacityConfiguration.0.source', 'global_default')
            ->where('capacityEditable', true));

    $this->assertDatabaseCount('monthly_shift_capacities', 0);
});

it('displays existing custom monthly capacities', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    app(MonthlyShiftCapacityService::class)->saveAll(2026, 10, monthlyShiftCapacityManagementPayload()['capacities']);

    $this->actingAs($admin)
        ->get(monthlyShiftCapacityManagementUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('capacityConfiguration.0.main_count', 3)
            ->where('capacityConfiguration.0.optional_count', 2)
            ->where('capacityConfiguration.0.source', 'monthly_custom'));
});

it('saves and updates all five monthly shift capacities before roster creation', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $url = monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store');

    $this->actingAs($admin)->post($url, monthlyShiftCapacityManagementPayload())
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10])
        ->assertSessionHas('status', 'Shift capacity configuration saved successfully.');

    $this->assertDatabaseCount('monthly_shift_capacities', 5);
    $this->assertDatabaseHas('monthly_shift_capacities', [
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => ShiftType::query()->where('code', 'weekday_night')->value('id'),
        'main_count' => 2,
        'optional_count' => 0,
    ]);

    $updatedPayload = monthlyShiftCapacityManagementPayload([
        0 => ['main_count' => 4, 'optional_count' => 3],
    ]);
    $this->post($url, $updatedPayload)->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10]);

    $this->assertDatabaseCount('monthly_shift_capacities', 5);
    $this->assertDatabaseHas('monthly_shift_capacities', [
        'year' => 2026,
        'month' => 10,
        'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->value('id'),
        'main_count' => 4,
        'optional_count' => 3,
    ]);
});

it('rejects invalid counts and unexpected shift types atomically while preserving saved values', function (array $override, string $error): void {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $service = app(MonthlyShiftCapacityService::class);
    $service->saveAll(2026, 10, monthlyShiftCapacityManagementPayload()['capacities']);
    $before = MonthlyShiftCapacity::query()->orderBy('shift_type_id')->get()->toArray();
    $payload = monthlyShiftCapacityManagementPayload($override);

    $this->actingAs($admin)->post(monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store'), $payload)
        ->assertSessionHasErrors($error);

    expect(MonthlyShiftCapacity::query()->orderBy('shift_type_id')->get()->toArray())->toBe($before);
    $this->assertDatabaseCount('monthly_shift_capacities', 5);
})->with([
    'negative Main count' => [[0 => ['main_count' => -1]], 'capacities.0.main_count'],
    'oversized Optional count' => [[1 => ['optional_count' => 256]], 'capacities.1.optional_count'],
    'unexpected shift type' => [[2 => ['shift_type' => 'inactive_shift']], 'capacities.2.shift_type'],
]);

it('rejects a batch with an invalid value before creating any monthly records', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $payload = monthlyShiftCapacityManagementPayload([4 => ['optional_count' => -1]]);

    $this->actingAs($admin)->post(monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store'), $payload)
        ->assertSessionHasErrors('capacities.4.optional_count');

    $this->assertDatabaseCount('monthly_shift_capacities', 0);
});

it('rejects configuration when one required shift type is inactive', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    ShiftType::query()->where('code', 'weekday_evening')->update(['is_active' => false]);

    $this->actingAs($admin)
        ->post(monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store'), monthlyShiftCapacityManagementPayload())
        ->assertSessionHasErrors('capacities');

    $this->assertDatabaseCount('monthly_shift_capacities', 0);
});

it('rejects capacity writes after Draft and Final roster creation', function (RosterStatus $status): void {
    [$admin] = monthlyShiftCapacityManagementSetup();
    Roster::create(['year' => 2026, 'month' => 10, 'status' => $status, 'created_by' => $admin->id]);

    $this->actingAs($admin)
        ->post(monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store'), monthlyShiftCapacityManagementPayload())
        ->assertSessionHasErrors('month');

    $this->assertDatabaseCount('monthly_shift_capacities', 0);
})->with([RosterStatus::Draft, RosterStatus::Final]);

it('keeps capacities locked after a Final roster is reopened as Draft', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $roster->update(['status' => RosterStatus::Final, 'finalized_at' => now(), 'finalized_by' => $admin->id]);

    $this->actingAs($admin)
        ->post(monthlyShiftCapacityManagementUrl('rosters.reopen'))
        ->assertRedirect();

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);
    $this->post(monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store'), monthlyShiftCapacityManagementPayload())
        ->assertSessionHasErrors('month');
    $this->assertDatabaseCount('monthly_shift_capacities', 0);
});

it('shows saved October snapshots read-only and rejects changes without altering snapshots', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $snapshotValues = RosterShiftCapacitySnapshot::query()
        ->orderBy('roster_shift_id')
        ->get(['roster_shift_id', 'main_count', 'optional_count'])
        ->toArray();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00:00'));

    $this->actingAs($admin)
        ->get(monthlyShiftCapacityManagementUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('capacityConfiguration', fn (Collection $capacities): bool => $capacities->pluck('shift_type.code')->all() === [
                'weekday_day',
                'weekday_evening',
                'weekday_night',
                'weekend_day',
                'weekend_night',
            ])
            ->where('isPlanningPeriod', false)
            ->where('capacityEditable', false)
            ->where('rosterStatus', 'draft')
            ->where('capacityConfiguration.0.main_count', 4)
            ->where('capacityConfiguration.0.optional_count', 2)
            ->where('capacityConfiguration.0.source', 'roster_snapshot'));

    $this->post(monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store'), monthlyShiftCapacityManagementPayload())
        ->assertSessionHasErrors('month');
    expect(RosterShiftCapacitySnapshot::query()
        ->orderBy('roster_shift_id')
        ->get(['roster_shift_id', 'main_count', 'optional_count'])
        ->toArray())->toBe($snapshotValues);
    $this->assertModelExists($roster);
});

it('reports inconsistent saved snapshots instead of falling back to global defaults', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $shiftTypeId = ShiftType::query()->where('code', 'weekday_day')->value('id');
    $shifts = $roster->shifts()->where('shift_type_id', $shiftTypeId)->orderBy('id')->get();
    DB::table('roster_shift_capacity_snapshots')
        ->where('roster_shift_id', $shifts->firstOrFail()->id)
        ->update(['main_count' => 1]);

    $this->actingAs($admin)
        ->get(monthlyShiftCapacityManagementUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('capacityConfiguration.0.source', 'inconsistent')
            ->where('capacityConfiguration.0.main_count', null)
            ->where('capacityConfiguration.0.error', 'Saved roster capacity snapshots are missing or inconsistent for this shift type.'));
});

it('uses saved roster capacity snapshots in historical staffing warnings', function () {
    [$admin] = monthlyShiftCapacityManagementSetup();
    $weekdayDay = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $weekdayDay->update(['main_count' => 15, 'optional_count' => 0]);
    app(RosterStructureService::class)->create(2026, 10, $admin);
    $weekdayDay->update(['main_count' => 1, 'optional_count' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00:00'));

    $this->actingAs($admin)
        ->get(monthlyShiftCapacityManagementUrl('monthly-setup.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('capacityConfiguration.0.main_count', 15)
            ->where('warnings', fn (Collection $warnings): bool => $warnings->contains(fn (array $warning): bool => $warning['message'] === 'Oct 1 Weekday Day may have insufficient availability: 14 doctors available for 15 required positions.')));
});

it('requires authentication to change monthly capacities', function () {
    monthlyShiftCapacityManagementSetup();

    $this->post(monthlyShiftCapacityManagementUrl('monthly-setup.capacities.store'), monthlyShiftCapacityManagementPayload())
        ->assertRedirectToRoute('login');

    $this->assertDatabaseCount('monthly_shift_capacities', 0);
});

it('keeps ordinary Monthly Setup requests and exclusions editable while a Draft roster locks capacities', function () {
    [$admin, $doctor] = monthlyShiftCapacityManagementSetup();
    $otherDoctor = Doctor::query()
        ->where('is_active', true)
        ->where('id', '!=', $doctor->id)
        ->firstOrFail();
    Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);

    $this->actingAs($admin)
        ->post(monthlyShiftCapacityManagementUrl('doctor-requests.store'), [
            'doctor_id' => $doctor->id,
            'request_type' => 'day_off',
            'request_date' => '2026-10-12',
        ])
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10])
        ->assertSessionHasNoErrors();
    $this->post(monthlyShiftCapacityManagementUrl('monthly-exclusions.store'), ['doctor_id' => $otherDoctor->id])
        ->assertRedirectToRoute('monthly-setup.show', ['year' => 2026, 'month' => 10])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('doctor_requests', 1);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 1);
    $this->assertDatabaseCount('monthly_shift_capacities', 0);
});
