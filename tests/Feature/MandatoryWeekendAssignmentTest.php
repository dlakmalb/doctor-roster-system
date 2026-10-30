<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\MonthlyWeekendPairConfirmation;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\RosterShiftCapacitySnapshot;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WeekendPairConfiguration;
use App\Models\WeekendRotationConfiguration;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\DoctorMonthlyWorkloadService;
use App\Services\GenerateRosterFromMonthlySetup;
use App\Services\MonthlyShiftCapacityService;
use App\Services\MonthlyWeekendPairService;
use App\Services\PreviousNightBoundaryService;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterCandidateRanker;
use App\Services\RosterDraftValidationService;
use App\Services\RosterPlanningHistoryService;
use App\Services\RosterStructureService;
use App\Services\WeekendPairRotationService;
use Carbon\CarbonImmutable;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

afterEach(function (): void {
    mt_srand();
});

/** @return array{admin: User, groups: array<string, list<int>>, october: Roster} */
function mandatoryWeekendFixture(bool $seven = false): array
{
    mt_srand(20261107);
    test()->travelTo(new DateTimeImmutable('2026-10-30 09:00:00'));
    test()->seed(ShiftTypesSeeder::class);
    $admin = User::factory()->create();
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-11-07', 'anchor_group' => 'B']);
    $groups = [];
    foreach (['A' => ['T', 'I', 'A', 'L', 'R', 'B'], 'B' => ['G', 'K', 'S', 'C', 'E', 'N']] as $group => $codes) {
        if ($seven) {
            $codes[] = $group.'7';
        }
        foreach ($codes as $code) {
            $doctor = Doctor::query()->create(['name' => 'Dr '.$code, 'short_code' => $code, 'is_active' => true]);
            DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => $group, 'effective_from_saturday' => '2026-10-03']);
            $groups[$group][] = $doctor->id;
        }
    }
    foreach (['M' => 'A', 'U' => 'B'] as $code => $group) {
        $doctor = Doctor::query()->create(['name' => 'Excluded '.$code, 'short_code' => $code, 'is_active' => true]);
        DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => $group, 'effective_from_saturday' => '2026-10-03']);
    }
    $capacities = app(MonthlyShiftCapacityService::class);
    foreach ([11, 12] as $month) {
        foreach (['weekday_day' => [3, 2], 'weekday_evening' => [2, 1], 'weekday_night' => [2, 0], 'weekend_day' => [3, 7], 'weekend_night' => [2, 0]] as $code => [$main, $optional]) {
            $capacities->create(2026, $month, $code, $main, $optional);
        }
    }
    $october = app(RosterStructureService::class)->create(2026, 10, $admin);
    $night = mandatoryWeekendShift($october, '2026-10-31', 'weekend_night');
    foreach (array_slice($groups['A'], 0, 2) as $index => $id) {
        $night->assignments()->create(['doctor_id' => $id, 'role' => RosterAssignmentRole::Main, 'slot_number' => $index + 1]);
    }
    $october->update(['status' => RosterStatus::Final, 'finalized_at' => now()]);
    mandatoryWeekendConfirm($admin, $groups);

    return compact('admin', 'groups', 'october');
}

/** @param array<string, list<int>> $groups */
function mandatoryWeekendConfirm(User $admin, array $groups, int $year = 2026, int $month = 11): void
{
    foreach (Doctor::query()->whereIn('short_code', ['M', 'U'])->pluck('id') as $id) {
        DoctorMonthlyExclusion::query()->firstOrCreate(['doctor_id' => $id, 'year' => $year, 'month' => $month]);
    }
    $service = app(MonthlyWeekendPairService::class);
    $state = $service->load($year, $month);
    $input = ['revision' => $state['revision'], 'groups' => []];
    foreach (['A', 'B'] as $group) {
        $input['groups'][$group] = ['effective_saturday' => $state['groups'][$group]['effective_saturday'], 'doctor_ids' => $groups[$group]];
    }
    $service->confirm($year, $month, $input, $admin->id);
}

function mandatoryWeekendShift(Roster $roster, string $date, string $code = 'weekend_day'): RosterShift
{
    return $roster->shifts()->with(['shiftType', 'assignments', 'capacitySnapshot'])->whereDate('shift_date', $date)
        ->whereHas('shiftType', fn ($query) => $query->where('code', $code))->sole();
}

/** @return list<int> */
function mandatoryWeekendMainIds(Roster $roster, string $date, string $code = 'weekend_day'): array
{
    return mandatoryWeekendShift($roster, $date, $code)->assignments->where('role', RosterAssignmentRole::Main)
        ->sortBy('slot_number')->pluck('doctor_id')->all();
}

function assertMandatoryWeekendSafe(Roster $roster): void
{
    $assignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))
        ->with('rosterShift.shiftType')->get();
    $eligibility = app(DoctorAssignmentEligibilityService::class);
    foreach ($assignments->groupBy('doctor_id') as $entries) {
        foreach ($entries as $entry) {
            foreach ($entries as $other) {
                if ($entry->id !== $other->id) {
                    expect($eligibility->roleAwareShiftConflict($entry->rosterShift, $entry->role, $other->rosterShift, $other->role))->toBeNull();
                }
            }
        }
    }
    $excluded = DoctorMonthlyExclusion::query()->where('year', $roster->year)->where('month', $roster->month)->pluck('doctor_id')->all();
    expect($assignments->whereIn('doctor_id', $excluded))->toHaveCount(0);
    foreach ($roster->shifts()->with(['assignments', 'capacitySnapshot'])->get() as $shift) {
        expect($shift->assignments->where('role', RosterAssignmentRole::Main))->toHaveCount($shift->savedCapacity()->main_count);
    }
}

it('generates every exact November Main pair and preserves October Final and all capacity snapshots', function () {
    ['admin' => $admin, 'october' => $october] = mandatoryWeekendFixture();
    $octoberBefore = $october->fresh()->toArray();
    $octoberAssignments = $october->shifts()->with('assignments')->get()->toArray();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $snapshots = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();
    $configurations = WeekendPairConfiguration::query()->get()->toArray();
    $confirmations = MonthlyWeekendPairConfirmation::query()->get()->toArray();

    app(RosterAssignmentGenerator::class)->generate($roster, $admin);

    foreach ([
        '2026-11-07' => [['G', 'K'], ['S', 'C']], '2026-11-08' => [['E', 'N'], ['G', 'K']],
        '2026-11-14' => [['T', 'I'], ['A', 'L']], '2026-11-15' => [['R', 'B'], ['T', 'I']],
        '2026-11-21' => [['S', 'C'], ['E', 'N']], '2026-11-22' => [['G', 'K'], ['S', 'C']],
        '2026-11-28' => [['A', 'L'], ['R', 'B']], '2026-11-29' => [['T', 'I'], ['A', 'L']],
    ] as $date => [$day, $night]) {
        foreach (['weekend_day' => $day, 'weekend_night' => $night] as $code => $codes) {
            $actual = array_map(fn (int $id): string => Doctor::query()->findOrFail($id)->short_code, mandatoryWeekendMainIds($roster, $date, $code));
            expect($actual)->toBe($codes);
        }
    }
    expect(mandatoryWeekendMainIds($roster, '2026-11-01'))->toHaveCount(3);
    expect(mandatoryWeekendShift($roster, '2026-11-01')->savedCapacity()->main_count)->toBe(3);
    $boundaryNightIds = mandatoryWeekendMainIds($october, '2026-10-31', 'weekend_night');
    $legacyIds = $roster->shifts()->whereDate('shift_date', '2026-11-01')->get()->flatMap(fn (RosterShift $shift) => $shift->assignments()->pluck('doctor_id'))->all();
    expect(array_intersect($boundaryNightIds, $legacyIds))->toBe([]);
    expect($roster->shifts()->count())->toBe(81);
    expect($october->fresh()->toArray())->toBe($octoberBefore);
    expect($october->shifts()->with('assignments')->get()->toArray())->toBe($octoberAssignments);
    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($snapshots);
    expect(WeekendPairConfiguration::query()->get()->toArray())->toBe($configurations);
    expect(MonthlyWeekendPairConfirmation::query()->get()->toArray())->toBe($confirmations);
    assertMandatoryWeekendSafe($roster);
    $hard = collect(app(RosterDraftValidationService::class)->validate($roster))->where('severity', 'Error');
    expect($hard)->toHaveCount(0);
    expect($roster->fresh()->generated_history_fingerprint)->not->toBeNull();
});

it('plans Friday Night and all Optional and Monday duties around the fixed weekend commitments', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);

    expect(array_intersect(mandatoryWeekendMainIds($roster, '2026-11-06', 'weekday_night'), array_slice($groups['B'], 0, 2)))->toBe([]);
    foreach (['2026-11-08' => array_slice($groups['B'], 2, 2), '2026-11-09' => array_slice($groups['B'], 0, 2)] as $date => $ids) {
        $assigned = $roster->shifts()->whereDate('shift_date', $date)->get()->flatMap(fn (RosterShift $shift) => $shift->assignments()->pluck('doctor_id'))->all();
        expect(array_intersect($assigned, $ids))->toBe([]);
    }
    assertMandatoryWeekendSafe($roster);
});

it('uses both seven doctor plans including the rotating additional Day doctor', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture(true);
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);

    $b = $groups['B'];
    expect(mandatoryWeekendMainIds($roster, '2026-11-07'))->toBe([$b[0], $b[1], $b[6]]);
    expect(mandatoryWeekendMainIds($roster, '2026-11-08'))->toBe([$b[4], $b[5], $b[6]]);
    expect(mandatoryWeekendMainIds($roster, '2026-11-21'))->toBe([$b[2], $b[3], $b[0]]);
    expect(mandatoryWeekendMainIds($roster, '2026-11-22'))->toBe([$b[6], $b[1], $b[0]]);
    foreach (['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'] as $saturday) {
        $plan = app(WeekendPairRotationService::class)->forSaturday($saturday);
        $sunday = CarbonImmutable::parse($saturday)->addDay()->toDateString();
        expect(mandatoryWeekendMainIds($roster, $saturday))->toBe($plan['shifts']['saturday_day']);
        expect(mandatoryWeekendMainIds($roster, $saturday, 'weekend_night'))->toBe($plan['shifts']['saturday_night']);
        expect(mandatoryWeekendMainIds($roster, $sunday))->toBe($plan['shifts']['sunday_day']);
        expect(mandatoryWeekendMainIds($roster, $sunday, 'weekend_night'))->toBe($plan['shifts']['sunday_night']);
    }
    assertMandatoryWeekendSafe($roster);
});

it('continues December pairs after a six to seven or seven to six mode change', function (bool $startsSeven) {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture(true);
    $extras = ['A' => array_pop($groups['A']), 'B' => array_pop($groups['B'])];
    if (! $startsSeven) {
        foreach ($extras as $id) {
            DoctorMonthlyExclusion::query()->create(['doctor_id' => $id, 'year' => 2026, 'month' => 11]);
        }
        mandatoryWeekendConfirm($admin, $groups);
    } else {
        foreach ($extras as $group => $id) {
            $groups[$group][] = $id;
        }
    }
    $november = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);
    $november->update(['status' => RosterStatus::Final]);
    foreach ($extras as $group => $id) {
        if ($startsSeven) {
            array_pop($groups[$group]);
            DoctorMonthlyExclusion::query()->create(['doctor_id' => $id, 'year' => 2026, 'month' => 12]);
        } else {
            $groups[$group][] = $id;
        }
    }
    mandatoryWeekendConfirm($admin, $groups, 2026, 12);

    $december = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 12, $admin);

    foreach (['2026-12-05', '2026-12-12'] as $date) {
        $plan = app(WeekendPairRotationService::class)->forSaturday($date);
        expect($plan['starting_pair'])->toBe(3);
        expect($plan['group_occurrence'])->toBe(2);
        expect(mandatoryWeekendMainIds($december, $date))->toBe($plan['shifts']['saturday_day']);
        expect(mandatoryWeekendMainIds($december, $date, 'weekend_night'))->toBe($plan['shifts']['saturday_night']);
        expect(mandatoryWeekendMainIds($december, $plan['sunday']))->toBe($plan['shifts']['sunday_day']);
        expect(mandatoryWeekendMainIds($december, $plan['sunday'], 'weekend_night'))->toBe($plan['shifts']['sunday_night']);
    }
    assertMandatoryWeekendSafe($december);
})->with(['six to seven' => false, 'seven to six' => true]);

it('preserves correct Main assignments and fills missing mandatory slots without duplicates', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $shift = mandatoryWeekendShift($roster, '2026-11-07');
    $existing = $shift->assignments()->create(['doctor_id' => $groups['B'][1], 'role' => RosterAssignmentRole::Main, 'slot_number' => 2]);
    $generator = app(RosterAssignmentGenerator::class);

    $generator->generate($roster, $admin);
    $first = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $generator->generate($roster, $admin);

    $this->assertModelExists($existing);
    expect(mandatoryWeekendMainIds($roster, '2026-11-07'))->toBe(array_slice($groups['B'], 0, 2));
    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($first);
    assertMandatoryWeekendSafe($roster);
});

it('rejects a manually mismatched Main and restores exact pairs only on explicit regeneration', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $shift = mandatoryWeekendShift($roster, '2026-11-07');
    $manual = $shift->assignments()->create(['doctor_id' => $groups['A'][0], 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $snapshots = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))->toThrow(ValidationException::class, 'existing Draft Main assignments');
    $this->assertModelExists($manual);
    app(RosterAssignmentGenerator::class)->regenerate($roster, $admin);

    $this->assertModelMissing($manual);
    expect(mandatoryWeekendMainIds($roster, '2026-11-07'))->toBe(array_slice($groups['B'], 0, 2));
    foreach (['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'] as $date) {
        $plan = app(WeekendPairRotationService::class)->forSaturday($date);
        expect(mandatoryWeekendMainIds($roster, $date))->toBe($plan['shifts']['saturday_day']);
        expect(mandatoryWeekendMainIds($roster, $date, 'weekend_night'))->toBe($plan['shifts']['saturday_night']);
        expect(mandatoryWeekendMainIds($roster, $plan['sunday']))->toBe($plan['shifts']['sunday_day']);
        expect(mandatoryWeekendMainIds($roster, $plan['sunday'], 'weekend_night'))->toBe($plan['shifts']['sunday_night']);
    }
    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($snapshots);
});

it('preserves correct Main identities even when their existing slots are reversed', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $shift = mandatoryWeekendShift($roster, '2026-11-07');
    foreach (array_reverse(array_slice($groups['B'], 0, 2)) as $index => $id) {
        $shift->assignments()->create(['doctor_id' => $id, 'role' => RosterAssignmentRole::Main, 'slot_number' => $index + 1]);
    }
    $before = $shift->assignments()->orderBy('id')->get()->toArray();

    app(RosterAssignmentGenerator::class)->generate($roster, $admin);

    expect($shift->assignments()->where('role', RosterAssignmentRole::Main)->orderBy('id')->get()->toArray())->toBe($before);
    assertMandatoryWeekendSafe($roster);
});

it('rejects hard availability conflicts with the required doctor date shift and reason', function (string $conflict, string $reason) {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $id = $groups['B'][0];
    $day = mandatoryWeekendShift($roster, '2026-11-07');
    if ($conflict === 'off') {
        DoctorRequest::query()->create(['doctor_id' => $id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-07', 'shift_type_id' => null]);
    } elseif ($conflict === 'restriction') {
        DoctorMonthlyShiftRestriction::query()->create(['doctor_id' => $id, 'year' => 2026, 'month' => 11, 'shift_type_id' => $day->shift_type_id]);
    } elseif ($conflict === 'excluded') {
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $id, 'year' => 2026, 'month' => 11]);
    } elseif ($conflict === 'inactive') {
        Doctor::query()->whereKey($id)->update(['is_active' => false]);
    } elseif ($conflict === 'participation') {
        DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $id)->update(['is_participating' => false]);
    } else {
        $isNight = in_array($conflict, ['night', 'optional_night'], true);
        $date = $isNight ? '2026-11-06' : '2026-11-07';
        $code = $isNight ? 'weekday_night' : 'weekend_day';
        $conflictingShift = mandatoryWeekendShift($roster, $date, $code);
        if ($conflict === 'optional_night') {
            DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $conflictingShift->id)->update(['optional_count' => 1]);
        }
        $conflictingShift->assignments()->create(['doctor_id' => $id, 'role' => $conflict === 'night' ? RosterAssignmentRole::Main : RosterAssignmentRole::Optional, 'slot_number' => 1]);
    }
    $before = RosterAssignment::query()->orderBy('id')->get()->toArray();

    try {
        app(RosterAssignmentGenerator::class)->generate($roster, $admin);
        $this->fail('Mandatory availability must not be silently substituted.');
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['roster']))->toContain('Dr G', '2026-11-07', 'Weekend Day', $reason);
    }

    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect($roster->fresh()->last_generated_at)->toBeNull();
})->with([
    'full Day Off' => ['off', 'day off overlap'],
    'Main restriction' => ['restriction', 'monthly shift restriction'],
    'monthly exclusion' => ['excluded', 'monthly exclusion'],
    'inactive' => ['inactive', 'active, participating'],
    'not participating' => ['participation', 'active, participating'],
    'Friday Main Night' => ['night', 'previous night assignment'],
    'Friday Optional Night' => ['optional_night', 'previous night assignment'],
    'same date Optional' => ['duplicate', 'same start date'],
]);

it('leaves no new Draft or participation or capacities after an initial mandatory planning failure', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    DoctorRequest::query()->create(['doctor_id' => $groups['B'][0], 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-07']);
    $snapshots = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    expect(fn () => app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin))->toThrow(ValidationException::class, 'Dr G');

    $this->assertDatabaseMissing('rosters', ['year' => 2026, 'month' => 11]);
    $this->assertDatabaseMissing('doctor_monthly_participations', ['year' => 2026, 'month' => 11]);
    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($snapshots);
    expect(app(MonthlyWeekendPairService::class)->load(2026, 11)['read_only'])->toBeFalse();
});

it('retains an existing Draft and its assignments after a regeneration planning failure', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);
    $before = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $metadata = $roster->fresh()->toArray();
    DoctorRequest::query()->create(['doctor_id' => $groups['B'][0], 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-07']);

    expect(fn () => app(RosterAssignmentGenerator::class)->regenerate($roster, $admin))->toThrow(ValidationException::class, 'Dr G');

    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect($roster->fresh()->toArray())->toBe($metadata);
});

it('rejects a saved Day or Night capacity mismatch without rewriting snapshots', function (string $code, int $count) {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $shift = mandatoryWeekendShift($roster, '2026-11-07', $code);
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $shift->id)->update(['main_count' => $count]);
    $snapshots = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))->toThrow(ValidationException::class, 'saved Main capacity');

    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($snapshots);
    expect($roster->fresh()->last_generated_at)->toBeNull();
})->with(['Day mismatch' => ['weekend_day', 3], 'Night mismatch' => ['weekend_night', 1]]);

it('rejects missing or stale confirmation in an existing Draft without fallback', function (string $change) {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    if ($change === 'missing') {
        DB::table('monthly_weekend_pair_confirmations')->where('year', 2026)->where('month', 11)->delete();
    } else {
        DB::table('monthly_weekend_pair_confirmations')->where('year', 2026)->where('month', 11)->update(['population_fingerprint' => 'stale']);
    }

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))->toThrow(ValidationException::class, 'confirmation is missing or requires attention');

    expect($roster->shifts()->get()->sum(fn (RosterShift $shift): int => $shift->assignments()->count()))->toBe(0);
})->with(['missing', 'stale']);

it('rejects changed effective group membership without selecting an opposite-group substitute', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['B'][0], 'group_code' => 'A', 'effective_from_saturday' => '2026-11-07']);

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))->toThrow(ValidationException::class, 'does not have Group B membership');

    expect($roster->fresh()->last_generated_at)->toBeNull();
});

it('rejects a missing effective scheduled-group pair configuration after activation', function () {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    DB::table('weekend_pair_configurations')->where('group_code', 'B')->update(['effective_saturday' => '2026-12-05']);

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))->toThrow(ValidationException::class, 'no effective pair configuration');

    expect($roster->fresh()->last_generated_at)->toBeNull();
});

it('uses saved capacities after global defaults change and allows Optional vacancies', function () {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $snapshots = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();
    ShiftType::query()->update(['main_count' => 1, 'optional_count' => 0]);

    app(RosterAssignmentGenerator::class)->generate($roster, $admin);

    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($snapshots);
    $issues = collect(app(RosterDraftValidationService::class)->validate($roster));
    expect($issues->where('severity', 'Error'))->toHaveCount(0);
    expect($issues->where('code', 'unfilled_optional_slot')->pluck('severity')->all())->toContain('Warning');
});

it('rejects an unsatisfiable Main schedule rather than reporting successful pair generation', function () {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    foreach (Doctor::query()->get() as $doctor) {
        DoctorRequest::query()->create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-03']);
    }

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))->toThrow(ValidationException::class, 'unable to fill all saved Main positions');

    expect($roster->fresh()->last_generated_at)->toBeNull();
    expect($roster->shifts()->get()->sum(fn (RosterShift $shift): int => $shift->assignments()->count()))->toBe(0);
});

it('counts mandatory duties once in ranking workload previews and next-month planned history', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $ranker = app(RosterCandidateRanker::class);
    app()->instance(RosterCandidateRanker::class, $ranker);
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);
    $recorded = $ranker->recordedAssignmentsSnapshot();
    $entries = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))
        ->with('rosterShift.shiftType')->get();
    $history = app(RosterPlanningHistoryService::class)->forMonth(2026, 11);
    $rows = collect(app(DoctorMonthlyWorkloadService::class)->preview($roster, $history)['rows'])->keyBy('doctor_id');

    foreach (array_merge($groups['A'], $groups['B']) as $id) {
        $main = $entries->where('doctor_id', $id)->where('role', RosterAssignmentRole::Main);
        $minutes = $main->sum(fn (RosterAssignment $assignment): int => $assignment->rosterShift->shiftType->duration_minutes);
        $nights = $main->filter(fn (RosterAssignment $assignment): bool => $assignment->rosterShift->shiftType->is_overnight)->count();
        expect($recorded['scheduledMinutes'][$id] ?? 0)->toBe($minutes);
        expect($recorded['nightCounts'][$id] ?? 0)->toBe($nights);
        expect($rows[$id]['actual_worked_minutes'])->toBe($minutes);
        expect($rows[$id]['actual_night_duty_count'])->toBe($nights);
    }
    expect($recorded['weekendCounts'][$groups['B'][0]]['2026-11-07'])->toBe(2);
    $roster->update(['status' => RosterStatus::Final]);
    $nextHistory = app(RosterPlanningHistoryService::class)->forMonth(2026, 12);
    expect($nextHistory->get($groups['B'][0])->actual_worked_minutes)->toBe($rows[$groups['B'][0]]['actual_worked_minutes']);
    expect($nextHistory->get($groups['B'][0])->actual_night_duty_count)->toBe($rows[$groups['B'][0]]['actual_night_duty_count']);
});

it('keeps workload ranking for ordinary weekday Main and treats preferences as soft', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $dayType = ShiftType::query()->where('code', 'weekend_day')->sole();
    DoctorRequest::query()->create(['doctor_id' => $groups['B'][0], 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-11-08', 'shift_type_id' => $dayType->id]);
    $eveningType = ShiftType::query()->where('code', 'weekday_evening')->sole();
    DoctorMonthlyWeekdayPreference::query()->create(['doctor_id' => $groups['B'][0], 'year' => 2026, 'month' => 11, 'shift_type_id' => $eveningType->id, 'weekday' => 1]);
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);

    expect(mandatoryWeekendMainIds($roster, '2026-11-08', 'weekend_night'))->toBe(array_slice($groups['B'], 0, 2));
    $warnings = collect(app(RosterDraftValidationService::class)->validate($roster));
    expect($warnings->where('code', 'preferred_work_unfulfilled')->pluck('severity')->all())->toContain('Warning');
    expect($warnings->where('severity', 'Error'))->toHaveCount(0);
    expect(mandatoryWeekendMainIds($roster, '2026-11-09', 'weekday_day'))->toHaveCount(3);
    $ranker = app(RosterCandidateRanker::class);
    $shifts = $roster->shifts()->with(['shiftType', 'assignments'])->get();
    $ranker->initialize($shifts, collect(), collect(), CarbonImmutable::parse('2026-11-01'));
    $ordinary = mandatoryWeekendShift($roster, '2026-11-10', 'weekday_evening');
    $candidates = Doctor::query()->whereIn('id', array_merge($groups['A'], $groups['B']))->get();
    $totals = $ranker->recordedAssignmentsSnapshot()['scheduledMinutes'];
    $ordered = $ranker->ordered($candidates, $ordinary, RosterAssignmentRole::Main, collect());
    expect($totals[$ordered->first()->id])->toBe(min(array_map(fn (Doctor $doctor): int => $totals[$doctor->id], $candidates->all())));
});

it('allows a Full Day Off doctor in an eligible ordinary Optional position', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $id = $groups['B'][0];
    DoctorRequest::query()->create(['doctor_id' => $id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-03']);
    $optional = mandatoryWeekendShift($roster, '2026-11-03', 'weekday_day')->assignments()
        ->create(['doctor_id' => $id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);

    app(RosterAssignmentGenerator::class)->generate($roster, $admin);

    $this->assertModelExists($optional);
    assertMandatoryWeekendSafe($roster);
});

/** @return array{admin: User, groups: array<string, list<int>>, july: Roster, august: Roster, plan: array<string, mixed>} */
function mandatoryWeekendBoundaryFixture(bool $seven = false): array
{
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture($seven);
    mandatoryWeekendConfirm($admin, $groups, 2027, 7);
    $july = app(RosterStructureService::class)->create(2027, 7, $admin);
    $plan = app(WeekendPairRotationService::class)->forSaturday('2027-07-31');
    foreach (['day', 'night'] as $duty) {
        $shift = mandatoryWeekendShift($july, '2027-07-31', 'weekend_'.$duty);
        foreach ($plan['shifts']['saturday_'.$duty] as $index => $id) {
            $shift->assignments()->create(['doctor_id' => $id, 'role' => RosterAssignmentRole::Main, 'slot_number' => $index + 1]);
        }
    }
    $july->update(['status' => RosterStatus::Final]);
    $groups = array_map('array_reverse', $groups);
    mandatoryWeekendConfirm($admin, $groups, 2027, 8);
    $august = app(RosterStructureService::class)->create(2027, 8, $admin);

    return compact('admin', 'groups', 'july', 'august', 'plan');
}

it('uses the Final historical Saturday plan for Sunday despite a new monthly pair order', function (bool $seven) {
    ['admin' => $admin, 'july' => $july, 'august' => $august, 'plan' => $plan] = mandatoryWeekendBoundaryFixture($seven);
    $historical = $july->shifts()->with(['assignments', 'capacitySnapshot'])->get()->toArray();

    app(RosterAssignmentGenerator::class)->generate($august, $admin);

    expect(mandatoryWeekendMainIds($august, '2027-08-01'))->toBe($plan['shifts']['sunday_day']);
    expect(mandatoryWeekendMainIds($august, '2027-08-01', 'weekend_night'))->toBe($plan['shifts']['sunday_night']);
    $firstSundayIds = $august->shifts()->whereDate('shift_date', '2027-08-01')->get()->flatMap(fn (RosterShift $shift) => $shift->assignments()->pluck('doctor_id'))->all();
    expect(array_intersect($firstSundayIds, $plan['shifts']['saturday_night']))->toBe([]);
    expect($july->shifts()->with(['assignments', 'capacitySnapshot'])->get()->toArray())->toBe($historical);
    assertMandatoryWeekendSafe($august);
})->with(['six doctors' => false, 'seven doctors' => true]);

it('rejects incompatible historical Saturday evidence without changing either roster', function (string $change) {
    ['admin' => $admin, 'july' => $july, 'august' => $august, 'groups' => $groups] = mandatoryWeekendBoundaryFixture();
    $day = mandatoryWeekendShift($july, '2027-07-31');
    if ($change === 'identity') {
        DB::table('roster_assignments')->where('roster_shift_id', $day->id)->where('role', RosterAssignmentRole::Main->value)->where('slot_number', 1)->update(['doctor_id' => $groups['A'][0]]);
    } elseif ($change === 'missing') {
        DB::table('roster_assignments')->where('roster_shift_id', $day->id)->delete();
    } elseif ($change === 'capacity') {
        DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $day->id)->update(['main_count' => 3]);
    } elseif ($change === 'participation') {
        DoctorMonthlyParticipation::query()->where('roster_id', $july->id)->where('doctor_id', $day->assignments->first()->doctor_id)->update(['is_participating' => false]);
    } elseif ($change === 'draft') {
        $july->update(['status' => RosterStatus::Draft]);
    } else {
        DB::table('monthly_weekend_pair_confirmations')->where('year', 2027)->where('month', 7)->delete();
    }
    $historical = $july->shifts()->with(['assignments', 'capacitySnapshot'])->get()->toArray();

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($august, $admin))->toThrow(ValidationException::class);

    expect($july->shifts()->with(['assignments', 'capacitySnapshot'])->get()->toArray())->toBe($historical);
    expect($august->fresh()->last_generated_at)->toBeNull();
    expect($august->shifts()->get()->sum(fn (RosterShift $shift): int => $shift->assignments()->count()))->toBe(0);
})->with(['identity', 'missing', 'capacity', 'participation', 'draft', 'confirmation']);

it('carries mandatory month-end Sunday Night recovery into every Monday role in the next month', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    mandatoryWeekendConfirm($admin, $groups, 2027, 1);
    $january = app(RosterStructureService::class)->create(2027, 1, $admin);
    $plan = app(WeekendPairRotationService::class)->forSaturday('2027-01-30');
    $night = mandatoryWeekendShift($january, '2027-01-31', 'weekend_night');
    foreach ($plan['shifts']['sunday_night'] as $index => $id) {
        $night->assignments()->create(['doctor_id' => $id, 'role' => RosterAssignmentRole::Main, 'slot_number' => $index + 1]);
    }
    $january->update(['status' => RosterStatus::Final]);
    mandatoryWeekendConfirm($admin, $groups, 2027, 2);
    $february = app(RosterStructureService::class)->create(2027, 2, $admin);

    app(RosterAssignmentGenerator::class)->generate($february, $admin);

    $mondayIds = $february->shifts()->whereDate('shift_date', '2027-02-01')->get()->flatMap(fn (RosterShift $shift) => $shift->assignments()->pluck('doctor_id'))->all();
    expect(array_intersect($mondayIds, $plan['shifts']['sunday_night']))->toBe([]);
    assertMandatoryWeekendSafe($february);
});

it('requires Final Saturday assignment evidence even when an initial Night boundary snapshot exists', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    mandatoryWeekendConfirm($admin, $groups, 2027, 7);
    mandatoryWeekendConfirm($admin, $groups, 2027, 8);
    $plan = app(WeekendPairRotationService::class)->forSaturday('2027-07-31');
    app(PreviousNightBoundaryService::class)->saveInitialSnapshot(2027, 7, $plan['shifts']['saturday_night']);
    $august = app(RosterStructureService::class)->create(2027, 8, $admin);

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($august, $admin))->toThrow(ValidationException::class, 'finalize the historical Saturday roster');

    $this->assertDatabaseMissing('rosters', ['year' => 2027, 'month' => 7]);
    expect($august->fresh()->last_generated_at)->toBeNull();
});

it('rolls back deleted and partially inserted assignments when regeneration persistence fails', function () {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);
    $before = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $metadata = $roster->fresh()->toArray();
    $created = 0;
    RosterAssignment::creating(function () use (&$created): void {
        if (++$created === 4) {
            throw new RuntimeException('Simulated mandatory persistence failure');
        }
    });

    try {
        expect(fn () => app(RosterAssignmentGenerator::class)->regenerate($roster, $admin))->toThrow(RuntimeException::class, 'Simulated mandatory persistence failure');
    } finally {
        RosterAssignment::flushEventListeners();
    }

    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect($roster->fresh()->toArray())->toBe($metadata);
});

it('rolls back a new pair-enabled roster after partial assignment persistence', function () {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $before = RosterAssignment::query()->orderBy('id')->get()->toArray();
    $created = 0;
    RosterAssignment::creating(function () use (&$created): void {
        if (++$created === 4) {
            throw new RuntimeException('Simulated initial mandatory persistence failure');
        }
    });

    try {
        expect(fn () => app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin))->toThrow(ValidationException::class, 'No assignments were saved');
    } finally {
        RosterAssignment::flushEventListeners();
    }

    $this->assertDatabaseMissing('rosters', ['year' => 2026, 'month' => 11]);
    $this->assertDatabaseMissing('doctor_monthly_participations', ['year' => 2026, 'month' => 11]);
    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('refuses generation and regeneration for a Final pair roster', function () {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);
    $roster->update(['status' => RosterStatus::Final]);
    $before = RosterAssignment::query()->orderBy('id')->get()->toArray();

    expect(fn () => app(RosterAssignmentGenerator::class)->generate($roster, $admin))->toThrow(ValidationException::class, 'Draft roster');
    expect(fn () => app(RosterAssignmentGenerator::class)->regenerate($roster, $admin))->toThrow(ValidationException::class, 'Draft roster');

    expect(RosterAssignment::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('returns actionable mandatory validation through each generation endpoint', function (string $route, bool $existingDraft) {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    if ($existingDraft) {
        app(RosterStructureService::class)->create(2026, 11, $admin);
    }
    DoctorRequest::query()->create(['doctor_id' => $groups['B'][0], 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-07']);

    $this->actingAs($admin)->post(route($route, ['year' => 2026, 'month' => 11]))
        ->assertSessionHasErrors(['roster' => 'Dr G (G): 2026-11-07 Weekend Day mandatory Main conflicts with day off overlap. Resolve availability or the conflicting Draft assignment; no substitute was selected.']);

    expect(Roster::query()->where('year', 2026)->where('month', 11)->count())->toBe($existingDraft ? 1 : 0);
    $this->assertDatabaseCount('roster_assignments', 2);
})->with([
    'Monthly Setup initial generation' => ['monthly-setup.generate-roster', false],
    'Monthly Setup existing Draft' => ['monthly-setup.generate-roster', true],
    'fill unfilled Draft' => ['rosters.generate', true],
    'regenerate Draft' => ['rosters.regenerate', true],
]);
