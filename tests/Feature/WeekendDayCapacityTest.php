<?php

use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\MonthlyWeekendPairConfirmation;
use App\Models\Roster;
use App\Models\RosterShift;
use App\Models\RosterShiftCapacitySnapshot;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WeekendPairConfiguration;
use App\Models\WeekendRotationConfiguration;
use App\Services\MonthlySetupService;
use App\Services\MonthlyShiftCapacityService;
use App\Services\MonthlyWeekendPairService;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use App\Services\WeekendDayCapacityService;
use App\Services\WeekendPairConfigurationService;
use App\Services\WeekendPairRotationService;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{admin: User, groups: array<string, list<int>>} */
function weekendCapacityFixture(int $a = 6, int $b = 6): array
{
    test()->seed(ShiftTypesSeeder::class);
    $admin = User::factory()->create();
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-11-07', 'anchor_group' => 'B']);
    $groups = [];
    foreach (['A' => $a, 'B' => $b] as $group => $count) {
        for ($position = 1; $position <= $count; $position++) {
            $doctor = Doctor::query()->create(['name' => "Capacity {$group}{$position}", 'short_code' => "{$group}{$position}", 'is_active' => true]);
            DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => $group, 'effective_from_saturday' => '2026-10-31']);
            $groups[$group][] = $doctor->id;
        }
    }

    return ['admin' => $admin, 'groups' => $groups];
}

/** @param array<string, list<int>> $groups */
function confirmWeekendCapacityMonth(User $admin, array $groups, int $year = 2026, int $month = 11): array
{
    $service = app(MonthlyWeekendPairService::class);
    $state = $service->load($year, $month);
    $payload = ['revision' => $state['revision'], 'groups' => []];
    foreach (['A', 'B'] as $group) {
        $payload['groups'][$group] = ['effective_saturday' => $state['groups'][$group]['effective_saturday'], 'doctor_ids' => $groups[$group]];
    }

    return $service->confirm($year, $month, $payload, $admin->id);
}

function weekendCapacityShift(Roster $roster, string $date, string $code = 'weekend_day'): RosterShift
{
    return $roster->shifts()->whereDate('shift_date', $date)
        ->whereHas('shiftType', fn ($query) => $query->where('code', $code))->firstOrFail();
}

it('snapshots each scheduled group mode independently on both days without changing other capacities', function (int $a, int $b, int $aMain, int $bMain) {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture($a, $b);
    $capacities = app(MonthlyShiftCapacityService::class);
    $capacities->create(2026, 11, 'weekend_day', 9, 4);
    $capacities->create(2026, 11, 'weekend_night', 2, 1);
    $capacities->create(2026, 11, 'weekday_day', 5, 3);
    $capacities->create(2026, 11, 'weekday_evening', 1, 2);
    $capacities->create(2026, 11, 'weekday_night', 2, 1);
    confirmWeekendCapacityMonth($admin, $groups);
    $versionsBefore = WeekendPairConfiguration::query()->get()->toArray();
    $confirmationsBefore = MonthlyWeekendPairConfirmation::query()->get()->toArray();
    $planBefore = app(WeekendPairRotationService::class)->forSaturday('2026-11-21');

    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);

    foreach (['2026-11-07' => $bMain, '2026-11-08' => $bMain, '2026-11-14' => $aMain, '2026-11-15' => $aMain, '2026-11-21' => $bMain, '2026-11-22' => $bMain, '2026-11-28' => $aMain, '2026-11-29' => $aMain] as $date => $expected) {
        $snapshot = weekendCapacityShift($roster, $date)->savedCapacity();
        expect([$snapshot->main_count, $snapshot->optional_count])->toBe([$expected, 4]);
        $night = weekendCapacityShift($roster, $date, 'weekend_night')->savedCapacity();
        expect([$night->main_count, $night->optional_count])->toBe([2, 1]);
    }
    $legacy = weekendCapacityShift($roster, '2026-11-01')->savedCapacity();
    expect([$legacy->main_count, $legacy->optional_count])->toBe([9, 4]);
    foreach (['weekday_day' => [5, 3], 'weekday_evening' => [1, 2], 'weekday_night' => [2, 1]] as $code => $expected) {
        $snapshot = weekendCapacityShift($roster, '2026-11-02', $code)->savedCapacity();
        expect([$snapshot->main_count, $snapshot->optional_count])->toBe($expected);
    }
    expect(WeekendPairConfiguration::query()->get()->toArray())->toBe($versionsBefore);
    expect(MonthlyWeekendPairConfirmation::query()->get()->toArray())->toBe($confirmationsBefore);
    expect(app(WeekendPairRotationService::class)->forSaturday('2026-11-21'))->toBe($planBefore);
    expect(ShiftType::query()->where('code', 'weekend_day')->firstOrFail()->main_count)->toBe(3);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 81);
    $this->assertDatabaseCount('roster_assignments', 0);
})->with([
    'six A and six B' => [6, 6, 2, 2],
    'seven A and six B' => [7, 6, 3, 2],
    'six A and seven B' => [6, 7, 2, 3],
    'seven A and seven B' => [7, 7, 3, 3],
]);

it('continues group alternation in December and requires its own monthly confirmation', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    confirmWeekendCapacityMonth($admin, $groups);
    expect(fn () => app(RosterStructureService::class)->create(2026, 12, $admin))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('rosters', 0);
    confirmWeekendCapacityMonth($admin, $groups, 2026, 12);

    $roster = app(RosterStructureService::class)->create(2026, 12, $admin);

    foreach (['2026-12-05' => 2, '2026-12-06' => 2, '2026-12-12' => 3, '2026-12-13' => 3, '2026-12-19' => 2, '2026-12-20' => 2, '2026-12-26' => 3, '2026-12-27' => 3] as $date => $main) {
        expect(weekendCapacityShift($roster, $date)->savedCapacity()->main_count)->toBe($main);
    }
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
});

it('uses the latest reconfirmation rather than a superseded confirmation', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    $first = confirmWeekendCapacityMonth($admin, $groups);
    $groups['A'] = array_reverse($groups['A']);
    $latest = confirmWeekendCapacityMonth($admin, $groups);

    $weekends = app(WeekendDayCapacityService::class)->forMonth(2026, 11, 9);

    expect($latest['confirmation']['id'])->not->toBe($first['confirmation']['id']);
    expect($weekends['2026-11-14']['configuration_id'])->toBe($latest['confirmation']['configuration_ids']['A']);
    expect($weekends['2026-11-28']['configuration_id'])->toBe($latest['confirmation']['configuration_ids']['A']);
    expect($weekends['2026-11-14']['main_count'])->toBe(3);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
});

it('rejects an unconfirmed configuration version appearing later in the month', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture();
    confirmWeekendCapacityMonth($admin, $groups);
    app(WeekendPairConfigurationService::class)->create([
        'group_code' => 'A', 'effective_saturday' => '2026-11-28', 'mode' => 6,
        'doctor_ids' => array_reverse($groups['A']), 'pair_anchor_phase' => 0, 'extra_day_anchor_phase' => 0,
    ], $admin->id);

    expect(fn () => app(RosterStructureService::class)->create(2026, 11, $admin))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('rosters', 0);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
});

it('rejects missing and stale confirmations before writing any roster structure', function (string $change) {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture();
    confirmWeekendCapacityMonth($admin, $groups);
    if ($change === 'missing') {
        DB::table('monthly_weekend_pair_confirmations')->delete();
    } elseif ($change === 'membership') {
        DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['A'][0], 'group_code' => 'B', 'effective_from_saturday' => '2026-11-14']);
    } elseif ($change === 'exclusion') {
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 11]);
    } elseif ($change === 'participation') {
        DoctorMonthlyParticipation::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 11, 'is_participating' => false]);
    } elseif ($change === 'unsupported') {
        DB::table('weekend_pair_configurations')->where('group_code', 'A')->update(['mode' => 5]);
    } else {
        Doctor::query()->whereKey($groups['A'][0])->update(['is_active' => false]);
    }
    $confirmationRows = MonthlyWeekendPairConfirmation::query()->get()->toArray();
    $participationRows = DoctorMonthlyParticipation::query()->get()->toArray();

    try {
        app(RosterStructureService::class)->create(2026, 11, $admin);
        test()->fail('Invalid pair capacity must prevent roster creation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('weekend_capacity');
        expect(implode(' ', $exception->errors()['weekend_capacity']))->toContain('Monthly Weekend Pairs');
    }

    $this->assertDatabaseCount('rosters', 0);
    $this->assertDatabaseCount('roster_shifts', 0);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
    expect(MonthlyWeekendPairConfirmation::query()->get()->toArray())->toBe($confirmationRows);
    expect(DoctorMonthlyParticipation::query()->get()->toArray())->toBe($participationRows);
})->with(['missing', 'membership', 'exclusion', 'participation', 'unsupported', 'inactive']);

it('reuses saved Draft and Final snapshots even after confirmation becomes stale', function (RosterStatus $status) {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    confirmWeekendCapacityMonth($admin, $groups);
    $structure = app(RosterStructureService::class);
    $roster = $structure->create(2026, 11, $admin);
    $roster->update(['status' => $status, 'last_generated_at' => now()]);
    $before = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 11]);

    expect($structure->create(2026, 11, $admin)->id)->toBe($roster->id);
    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect(fn () => weekendCapacityShift($roster, '2026-11-14')->savedCapacity()->update(['main_count' => 2]))->toThrow(LogicException::class);
})->with([RosterStatus::Draft, RosterStatus::Final]);

it('uses the preceding Saturday confirmed version on a cross-month Sunday despite a new monthly mode', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    $extra = array_pop($groups['A']);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $extra, 'year' => 2027, 'month' => 7]);
    confirmWeekendCapacityMonth($admin, $groups, 2027, 7);
    $july = app(RosterStructureService::class)->create(2027, 7, $admin);
    $saturday = weekendCapacityShift($july, '2027-07-31')->savedCapacity();
    $july->update(['status' => RosterStatus::Final]);
    $before = $saturday->toArray();
    $groups['A'][] = $extra;
    $groups['B'] = array_reverse($groups['B']);
    confirmWeekendCapacityMonth($admin, $groups, 2027, 8);

    $august = app(RosterStructureService::class)->create(2027, 8, $admin);

    expect($saturday->main_count)->toBe(2);
    expect(weekendCapacityShift($august, '2027-08-01')->savedCapacity()->main_count)->toBe(2);
    expect(weekendCapacityShift($august, '2027-08-07')->savedCapacity()->main_count)->toBe(3);
    expect(weekendCapacityShift($august, '2027-08-08')->savedCapacity()->main_count)->toBe(3);
    expect($saturday->fresh()->toArray())->toBe($before);
});

it('rejects a pair-enabled Sunday when its historical monthly confirmation is absent', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture();
    confirmWeekendCapacityMonth($admin, $groups, 2027, 7);
    confirmWeekendCapacityMonth($admin, $groups, 2027, 8);
    DB::table('monthly_weekend_pair_confirmations')->where('year', 2027)->where('month', 7)->delete();

    expect(fn () => app(RosterStructureService::class)->create(2027, 8, $admin))->toThrow(ValidationException::class, 'July 2027');
    $this->assertDatabaseCount('rosters', 0);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
});

it('rejects a Sunday that would conflict with a preceding Final Saturday saved capacity', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture();
    confirmWeekendCapacityMonth($admin, $groups, 2027, 7);
    $july = app(RosterStructureService::class)->create(2027, 7, $admin);
    $saturday = weekendCapacityShift($july, '2027-07-31');
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $saturday->id)->update(['main_count' => 3]);
    $july->update(['status' => RosterStatus::Final]);
    confirmWeekendCapacityMonth($admin, $groups, 2027, 8);
    $before = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    expect(fn () => app(RosterStructureService::class)->create(2027, 8, $admin))->toThrow(ValidationException::class, 'preceding Saturday saved');

    $this->assertDatabaseMissing('rosters', ['year' => 2027, 'month' => 8]);
    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('shows actionable preview errors and accepts monthly legacy Main without overriding automatic Main', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    confirmWeekendCapacityMonth($admin, $groups);
    $october = app(RosterStructureService::class)->create(2026, 10, $admin);
    $october->update(['status' => RosterStatus::Final]);
    snapshotRosterParticipation($october, completeMonthEndNight: true);
    $this->withoutVite();
    $this->travelTo(new DateTimeImmutable('2026-10-30 09:00:00'));
    $values = [];
    foreach (ShiftType::query()->get() as $type) {
        $values[] = ['shift_type' => $type->code, 'main_count' => $type->code === 'weekend_day' ? 9 : $type->main_count, 'optional_count' => $type->code === 'weekend_day' ? 4 : $type->optional_count];
    }
    $this->actingAs($admin)->post(route('monthly-setup.capacities.store', ['year' => 2026, 'month' => 11]), ['capacities' => $values])->assertSessionHasNoErrors();

    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 11]))->assertInertia(fn (Assert $page) => $page
        ->where('weekendCapacityError', null)
        ->where('capacityConfiguration.3.main_count', 9)
        ->where('capacityConfiguration.3.optional_count', 4)
        ->where('weekendDayCapacities.0.source', 'legacy')
        ->where('weekendDayCapacities.0.main_count', 9)
        ->where('weekendDayCapacities.1.group', 'B')->where('weekendDayCapacities.1.main_count', 2)
        ->where('weekendDayCapacities.2.group', 'A')->where('weekendDayCapacities.2.main_count', 3));
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 11]);
    $page = app(MonthlySetupService::class)->build(2026, 11);
    expect($page['weekendCapacityError'])->toContain('Monthly Weekend Pairs');
    expect($page['weekendDayCapacities'])->toBe([]);
});

it('displays mixed saved capacities without recalculating or reporting them as inconsistent', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    confirmWeekendCapacityMonth($admin, $groups);
    app(RosterStructureService::class)->create(2026, 11, $admin);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 11]);

    $page = app(MonthlySetupService::class)->build(2026, 11);
    $capacity = collect($page['capacityConfiguration'])->firstWhere('shift_type.code', 'weekend_day');

    expect($capacity['source'])->toBe('roster_snapshot');
    expect($capacity['error'])->toBeNull();
    expect(collect($capacity['per_shift'])->keyBy('date')['2026-11-07']['main_count'])->toBe(2);
    expect(collect($capacity['per_shift'])->keyBy('date')['2026-11-14']['main_count'])->toBe(3);
    expect($page['weekendCapacityError'])->toBeNull();
});

it('still flags missing or conflicting saved Weekend Day snapshots', function (string $damage) {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    confirmWeekendCapacityMonth($admin, $groups);
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $sunday = weekendCapacityShift($roster, '2026-11-08');
    if ($damage === 'missing') {
        DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $sunday->id)->delete();
    } else {
        DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $sunday->id)->update(['main_count' => 3]);
    }

    $page = app(MonthlySetupService::class)->build(2026, 11);
    $capacity = collect($page['capacityConfiguration'])->firstWhere('shift_type.code', 'weekend_day');

    expect($capacity['source'])->toBe('inconsistent');
    expect($capacity['error'])->toContain('missing or inconsistent');
})->with(['missing', 'Sunday mismatch']);

it('keeps months before pair activation entirely on their monthly legacy capacities', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture();
    confirmWeekendCapacityMonth($admin, $groups);
    app(MonthlyShiftCapacityService::class)->create(2026, 10, 'weekend_day', 4, 5);

    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);

    foreach ($roster->shifts()->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->get() as $shift) {
        expect([$shift->savedCapacity()->main_count, $shift->savedCapacity()->optional_count])->toBe([4, 5]);
    }
    expect(app(WeekendDayCapacityService::class)->forMonth(2026, 10, 4)['2026-10-31']['source'])->toBe('legacy');
});

it('preserves all October Final capacity snapshots while creating November', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    $october = app(RosterStructureService::class)->create(2026, 10, $admin);
    $october->update(['status' => RosterStatus::Final]);
    $snapshots = fn (): array => RosterShiftCapacitySnapshot::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->orderBy('id')->get()->toArray();
    $before = $snapshots();
    confirmWeekendCapacityMonth($admin, $groups);

    app(RosterStructureService::class)->create(2026, 11, $admin);

    expect($snapshots())->toBe($before);
    expect($october->fresh()->status)->toBe(RosterStatus::Final);
});

it('uses November legacy capacity independently of the Final October Saturday', function (int $legacyMain) {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture();
    $capacities = app(MonthlyShiftCapacityService::class);
    $capacities->create(2026, 10, 'weekend_day', 3, 7);
    $october = app(RosterStructureService::class)->create(2026, 10, $admin);
    $saturday = weekendCapacityShift($october, '2026-10-31');
    $assignment = $saturday->assignments()->create(['doctor_id' => $groups['A'][0], 'role' => 'main', 'slot_number' => 1]);
    $october->update(['status' => RosterStatus::Final]);
    $before = $saturday->savedCapacity()->toArray();
    $assignedBefore = $assignment->toArray();
    $capacities->create(2026, 11, 'weekend_day', $legacyMain, 7);
    confirmWeekendCapacityMonth($admin, $groups);

    $november = app(RosterStructureService::class)->create(2026, 11, $admin);

    expect(weekendCapacityShift($november, '2026-11-01')->savedCapacity()->main_count)->toBe($legacyMain);
    expect(weekendCapacityShift($november, '2026-11-01', 'weekend_night')->savedCapacity()->main_count)->toBe(2);
    expect(app(WeekendDayCapacityService::class)->forMonth(2026, 11, $legacyMain)['2026-10-31']['source'])->toBe('legacy');
    expect(weekendCapacityShift($november, '2026-11-07')->savedCapacity()->main_count)->toBe(2);
    expect($saturday->savedCapacity()->fresh()->toArray())->toBe($before);
    expect($assignment->fresh()->toArray())->toEqual($assignedBefore);
    expect($october->fresh()->status)->toBe(RosterStatus::Final);
    expect($capacities->resolve(2026, 10, 'weekend_day')['main_count'])->toBe(3);
    expect(ShiftType::query()->where('code', 'weekend_day')->sole()->main_count)->toBe(3);
})->with(['corrected two' => 2, 'legitimate legacy three' => 3]);

it('rolls back roster participation shifts and snapshots together if snapshot persistence fails', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture();
    confirmWeekendCapacityMonth($admin, $groups);
    $enabled = true;
    $created = 0;
    Event::listen('eloquent.creating: '.RosterShiftCapacitySnapshot::class, function () use (&$enabled, &$created): void {
        if ($enabled && ++$created === 4) {
            throw new RuntimeException('Snapshot persistence failed.');
        }
    });

    try {
        expect(fn () => app(RosterStructureService::class)->create(2026, 11, $admin))->toThrow(RuntimeException::class, 'Snapshot persistence failed.');
    } finally {
        $enabled = false;
    }

    $this->assertDatabaseCount('rosters', 0);
    $this->assertDatabaseCount('roster_shifts', 0);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
});

it('generates regenerates and validates using the saved mixed capacities', function () {
    ['admin' => $admin, 'groups' => $groups] = weekendCapacityFixture(7, 6);
    confirmWeekendCapacityMonth($admin, $groups);
    $october = Roster::query()->create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final]);
    snapshotRosterParticipation($october, completeMonthEndNight: true);
    foreach (ShiftType::query()->get() as $type) {
        app(MonthlyShiftCapacityService::class)->create(2026, 11, $type->code, $type->main_count, 0);
    }
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $before = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();
    $validation = app(RosterDraftValidationService::class);
    $emptyIssues = collect($validation->validate($roster));
    foreach (['2026-11-07' => 2, '2026-11-08' => 2, '2026-11-14' => 3, '2026-11-15' => 3] as $date => $main) {
        $shift = weekendCapacityShift($roster, $date);
        expect($emptyIssues->where('code', 'unfilled_main_slot')->filter(fn (array $issue): bool => str_starts_with($issue['target'], "slot-{$shift->id}-main-")))->toHaveCount($main);
    }

    $generator = app(RosterAssignmentGenerator::class);
    $generator->generate($roster, $admin);
    $generator->regenerate($roster->fresh(), $admin);

    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect(collect($validation->validate($roster->fresh()))->whereIn('code', ['invalid_capacity_snapshot', 'assignment_outside_capacity']))->toBeEmpty();
    expect(weekendCapacityShift($roster, '2026-11-07')->savedCapacity()->main_count)->toBe(2);
    expect(weekendCapacityShift($roster, '2026-11-14')->savedCapacity()->main_count)->toBe(3);
});
