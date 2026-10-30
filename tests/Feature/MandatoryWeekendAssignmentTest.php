<?php

use App\Enums\ActualWorkExceptionType;
use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\MonthlyShiftCapacity;
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
use App\Services\RecreateDraftRoster;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterCandidateRanker;
use App\Services\RosterDraftValidationService;
use App\Services\RosterManualEditService;
use App\Services\RosterPlanningHistoryService;
use App\Services\RosterStructureService;
use App\Services\WeekendPairRotationService;
use Carbon\CarbonImmutable;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

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

/** @return array{admin: User, groups: array<string, list<int>>, october: Roster, roster: Roster, doctor: Doctor, payload: array<string, mixed>} */
function updatedMonthlySetupFixture(bool $generated = false, int $weekdayDayMainCount = 3): array
{
    $fixture = mandatoryWeekendFixture();
    app(MonthlyShiftCapacityService::class)->update(2026, 11, 'weekend_day', 2, 7);
    if ($weekdayDayMainCount !== 3) {
        app(MonthlyShiftCapacityService::class)->update(2026, 11, 'weekday_day', $weekdayDayMainCount, 2);
    }
    $roster = $generated
        ? app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $fixture['admin'])
        : app(RosterStructureService::class)->create(2026, 11, $fixture['admin']);
    $doctor = Doctor::query()->where('short_code', 'U')->sole();
    $review = app(RecreateDraftRoster::class)->monthlySetupReview($roster->fresh());
    expect($review)->not->toBeNull();

    return $fixture + compact('roster', 'doctor') + ['payload' => [
        'doctor_id' => $doctor->id, 'optional_count' => 3, 'confirmation' => true, 'revision' => $review['revision'],
    ]];
}

/** @return array<string, mixed> */
function updatedMonthlySetupSavedState(): array
{
    $state = [];
    foreach ([
        'rosters', 'roster_shifts', 'roster_assignments', 'roster_shift_capacity_snapshots',
        'doctor_monthly_participations', 'doctor_monthly_exclusions', 'monthly_shift_capacities',
        'monthly_weekend_pair_confirmations', 'weekend_pair_configurations', 'weekend_pair_configuration_doctors',
        'doctor_requests', 'doctor_monthly_weekday_preferences', 'doctor_monthly_shift_restrictions',
    ] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }

    return $state;
}

it('recreates updated monthly setup atomically while preserving requests rotations legacy boundaries and future confirmation history', function () {
    ['admin' => $admin, 'groups' => $groups, 'october' => $october, 'roster' => $roster, 'doctor' => $doctor] = updatedMonthlySetupFixture(true);
    $dayType = ShiftType::query()->where('code', 'weekday_day')->sole();
    DoctorRequest::query()->create(['doctor_id' => $doctor->id, 'request_date' => '2026-11-03', 'request_type' => DoctorRequestType::DayOff]);
    DoctorRequest::query()->create(['doctor_id' => $doctor->id, 'request_date' => '2026-11-04', 'request_type' => DoctorRequestType::PreferredWork, 'shift_type_id' => $dayType->id]);
    DoctorMonthlyWeekdayPreference::query()->create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 11, 'weekday' => 3, 'shift_type_id' => $dayType->id]);
    DoctorMonthlyShiftRestriction::query()->create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 11, 'shift_type_id' => ShiftType::query()->where('code', 'weekday_evening')->sole()->id]);
    mandatoryWeekendConfirm($admin, ['A' => $groups['A'], 'B' => array_reverse($groups['B'])], 2026, 12);
    mandatoryWeekendConfirm($admin, $groups, 2026, 12);
    $december = app(MonthlyWeekendPairService::class)->load(2026, 12);
    $before = updatedMonthlySetupSavedState();
    $octoberBefore = $october->fresh()->load('shifts.assignments', 'shifts.capacitySnapshot')->toArray();
    $oldShiftIds = $roster->shifts()->pluck('id')->all();
    $savedCapacities = $roster->shifts()->with('shiftType', 'capacitySnapshot')->get()->mapWithKeys(fn (RosterShift $shift): array => [
        $shift->shift_date->toDateString().'|'.$shift->shiftType->code => [$shift->savedCapacity()->main_count, $shift->savedCapacity()->optional_count],
    ])->all();
    $previous = MonthlyWeekendPairConfirmation::query()->where('year', 2026)->where('month', 11)->latest('id')->firstOrFail();
    $payload = ['doctor_id' => $doctor->id, 'optional_count' => 3, 'confirmation' => true,
        'revision' => app(RecreateDraftRoster::class)->monthlySetupReview($roster->fresh())['revision']];

    $this->actingAs($admin)->post(route('rosters.recreate-updated-setup', ['year' => 2026, 'month' => 11]), $payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('rosters.show', ['year' => 2026, 'month' => 11]));

    $this->assertDatabaseMissing('doctor_monthly_exclusions', ['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 11]);
    $this->assertDatabaseHas('doctor_monthly_exclusions', ['doctor_id' => Doctor::query()->where('short_code', 'M')->sole()->id, 'year' => 2026, 'month' => 11]);
    expect($roster->fresh()->id)->toBe($roster->id);
    expect($roster->fresh()->last_generated_at)->not->toBeNull();
    expect($roster->shifts()->whereIn('id', $oldShiftIds)->exists())->toBeFalse();
    $shifts = $roster->shifts()->with('shiftType', 'capacitySnapshot', 'assignments')->get();
    expect($shifts)->toHaveCount(81);
    expect($shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count))->toBe(183);
    expect($shifts->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->optional_count))->toBe(147);
    foreach ($shifts as $shift) {
        $key = $shift->shift_date->toDateString().'|'.$shift->shiftType->code;
        $expected = $shift->shiftType->code === 'weekday_day' ? [3, 3] : $savedCapacities[$key];
        expect([$shift->savedCapacity()->main_count, $shift->savedCapacity()->optional_count])->toBe($expected);
    }
    expect(mandatoryWeekendShift($roster, '2026-11-01')->savedCapacity()->main_count)->toBe(2);
    foreach (['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'] as $saturday) {
        $plan = app(WeekendPairRotationService::class)->forSaturday($saturday);
        foreach (['saturday' => $saturday, 'sunday' => $plan['sunday']] as $day => $date) {
            foreach (['day', 'night'] as $duty) {
                expect(mandatoryWeekendMainIds($roster, $date, 'weekend_'.$duty))->toBe($plan['shifts'][$day.'_'.$duty]);
                expect(mandatoryWeekendMainIds($roster, $date, 'weekend_'.$duty))->not->toContain($doctor->id);
            }
        }
    }
    $current = app(MonthlyWeekendPairService::class)->load(2026, 11);
    expect($current['status'])->toBe('confirmed');
    $newConfirmation = MonthlyWeekendPairConfirmation::query()->findOrFail($current['confirmation']['id']);
    expect((int) $newConfirmation->supersedes_confirmation_id)->toBe($previous->id);
    expect($newConfirmation->population_fingerprint)->not->toBe($previous->population_fingerprint);
    expect((int) $newConfirmation->group_a_configuration_id)->toBe((int) $previous->group_a_configuration_id);
    expect((int) $newConfirmation->group_b_configuration_id)->toBe((int) $previous->group_b_configuration_id);
    expect($previous->fresh()->toArray())->toBe($previous->toArray());
    expect(MonthlyWeekendPairConfirmation::query()->where('month', 11)->where('year', 2026)->count())->toBe(2);
    $after = updatedMonthlySetupSavedState();
    foreach (['doctor_monthly_participations', 'weekend_pair_configurations', 'weekend_pair_configuration_doctors', 'doctor_requests', 'doctor_monthly_weekday_preferences', 'doctor_monthly_shift_restrictions'] as $table) {
        expect($after[$table])->toEqual($before[$table]);
    }
    $dayCapacityId = MonthlyShiftCapacity::query()->where('year', 2026)->where('month', 11)->where('shift_type_id', $dayType->id)->sole()->id;
    expect(collect($after['monthly_shift_capacities'])->reject(fn ($row): bool => $row->id === $dayCapacityId)->values()->all())
        ->toEqual(collect($before['monthly_shift_capacities'])->reject(fn ($row): bool => $row->id === $dayCapacityId)->values()->all());
    expect(app(MonthlyWeekendPairService::class)->load(2026, 12))->toBe($december);
    expect($october->fresh()->load('shifts.assignments', 'shifts.capacitySnapshot')->toArray())->toBe($octoberBefore);
    $violations = collect(app(RosterDraftValidationService::class)->validate($roster->fresh()));
    expect($violations->where('severity', 'Error'))->toBeEmpty();
    expect($violations->where('code', 'unfilled_optional_slot')->every(fn (array $violation): bool => $violation['severity'] === 'Warning'))->toBeTrue();
    assertMandatoryWeekendSafe($roster);
    $afterSuccess = updatedMonthlySetupSavedState();

    $this->post(route('rosters.recreate-updated-setup', ['year' => 2026, 'month' => 11]), $payload)->assertSessionHasErrors('revision');
    expect(updatedMonthlySetupSavedState())->toEqual($afterSuccess);
});

it('preserves a configured Weekday Day Main capacity other than three during updated setup recreation', function () {
    ['admin' => $admin, 'roster' => $roster, 'doctor' => $doctor, 'payload' => $payload] = updatedMonthlySetupFixture(weekdayDayMainCount: 2);
    $dayShifts = $roster->shifts()->whereHas('shiftType', fn ($query) => $query->where('code', 'weekday_day'));
    foreach ($dayShifts->with('capacitySnapshot')->get() as $shift) {
        expect([$shift->savedCapacity()->main_count, $shift->savedCapacity()->optional_count])->toBe([2, 2]);
    }

    app(RecreateDraftRoster::class)->handleUpdatedSetup($roster, $admin, $doctor->id, 3, true, $payload['revision']);

    $recreated = $dayShifts->with('capacitySnapshot', 'assignments')->get();
    expect($recreated)->toHaveCount(21);
    foreach ($recreated as $shift) {
        expect([$shift->savedCapacity()->main_count, $shift->savedCapacity()->optional_count])->toBe([2, 3]);
        expect($shift->assignments->where('role', RosterAssignmentRole::Main))->toHaveCount(2);
    }
    expect(app(MonthlyShiftCapacityService::class)->resolve(2026, 11, 'weekday_day'))->toBe(['main_count' => 2, 'optional_count' => 3]);
    expect(collect(app(RosterDraftValidationService::class)->validate($roster->fresh()))->where('severity', 'Error'))->toBeEmpty();
});

it('rolls back every updated setup change and keeps manual undo when recreation fails', function (string $failure) {
    ['admin' => $admin, 'roster' => $roster, 'doctor' => $doctor, 'payload' => $payload] = updatedMonthlySetupFixture(true);
    $enabled = true;
    $created = 0;
    if ($failure === 'hard validation') {
        $this->mock(RosterDraftValidationService::class, function ($mock): void {
            $mock->shouldReceive('validate')->once()->andReturn([['severity' => 'Error', 'message' => 'Simulated hard violation.']]);
        });
    } else {
        $model = $failure === 'snapshots' ? RosterShiftCapacitySnapshot::class : RosterAssignment::class;
        Event::listen('eloquent.creating: '.$model, function () use (&$enabled, &$created): void {
            if ($enabled && ++$created === 4) {
                throw new RuntimeException('Simulated updated setup failure.');
            }
        });
    }
    $undo = ['roster_id' => $roster->id, 'generated_at' => $roster->last_generated_at?->toDateTimeString(), 'test' => 'preserved'];
    session()->put(RosterManualEditService::UNDO_KEY, $undo);
    $before = updatedMonthlySetupSavedState();

    try {
        expect(fn () => app(RecreateDraftRoster::class)->handleUpdatedSetup($roster, $admin, $doctor->id, 3, true, $payload['revision']))
            ->toThrow($failure === 'hard validation' ? ValidationException::class : RuntimeException::class);
    } finally {
        $enabled = false;
    }

    expect(updatedMonthlySetupSavedState())->toEqual($before);
    expect(session()->get(RosterManualEditService::UNDO_KEY))->toBe($undo);
})->with(['snapshots', 'assignments', 'hard validation']);

it('rejects invalid updated setup submissions without changing the Draft', function (array $changes, string $field) {
    ['admin' => $admin, 'payload' => $payload] = updatedMonthlySetupFixture();
    $before = updatedMonthlySetupSavedState();

    $this->actingAs($admin)->post(route('rosters.recreate-updated-setup', ['year' => 2026, 'month' => 11]), array_replace($payload, $changes))
        ->assertSessionHasErrors($field);

    expect(updatedMonthlySetupSavedState())->toEqual($before);
})->with([
    'invalid doctor' => [['doctor_id' => -1], 'doctor_id'],
    'missing doctor' => [['doctor_id' => null], 'doctor_id'],
    'missing confirmation' => [['confirmation' => null], 'confirmation'],
    'declined confirmation' => [['confirmation' => false], 'confirmation'],
    'negative capacity' => [['optional_count' => -1], 'optional_count'],
    'fractional capacity' => [['optional_count' => 2.5], 'optional_count'],
    'excess capacity' => [['optional_count' => 256], 'optional_count'],
    'unchanged capacity' => [['optional_count' => 2], 'optional_count'],
    'missing revision' => [['revision' => null], 'revision'],
    'stale revision' => [['revision' => str_repeat('0', 64)], 'revision'],
]);

it('rejects unsafe updated setup states before destroying shifts', function (string $condition, string $field) {
    ['admin' => $admin, 'groups' => $groups, 'roster' => $roster, 'doctor' => $doctor, 'october' => $october, 'payload' => $payload] = updatedMonthlySetupFixture();
    switch ($condition) {
        case 'final':
            $roster->update(['status' => RosterStatus::Final, 'finalized_at' => now()]);
            break;
        case 'previously final':
            $roster->update(['finalized_at' => now()]);
            break;
        case 'actual work':
            $roster->update(['actual_work_confirmed_at' => now()]);
            break;
        case 'actual work exception':
            ActualWorkException::query()->create([
                'roster_shift_id' => $roster->shifts()->firstOrFail()->id,
                'exception_type' => ActualWorkExceptionType::OptionalWorked,
                'actual_doctor_id' => $doctor->id, 'recorded_by' => $admin->id,
            ]);
            break;
        case 'later roster':
            Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => RosterStatus::Draft]);
            break;
        case 'history':
            $october->update(['status' => RosterStatus::Draft]);
            break;
        case 'inactive':
            $doctor->update(['is_active' => false]);
            break;
        case 'nonparticipating':
            DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $doctor->id)->update(['is_participating' => false]);
            break;
        case 'missing participation':
            DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $doctor->id)->delete();
            break;
        case 'snapshot':
            $roster->shifts()->firstOrFail()->capacitySnapshot()->delete();
            break;
        case 'missing shift':
            $roster->shifts()->firstOrFail()->delete();
            break;
        case 'incompatible rotation':
            DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['B'][0], 'year' => 2026, 'month' => 11]);
            break;
        case 'no exclusion':
            $payload['doctor_id'] = $groups['B'][0];
            break;
        case 'changed assignments':
            mandatoryWeekendShift($roster, '2026-11-03', 'weekday_day')->assignments()->create([
                'doctor_id' => $groups['A'][0], 'role' => RosterAssignmentRole::Main, 'slot_number' => 1,
            ]);
            break;
        case 'changed capacity':
            MonthlyShiftCapacity::query()->where('year', 2026)->where('month', 11)
                ->where('shift_type_id', ShiftType::query()->where('code', 'weekday_evening')->sole()->id)->update(['optional_count' => 2]);
            break;
        case 'mismatched weekday Main':
            MonthlyShiftCapacity::query()->where('year', 2026)->where('month', 11)
                ->where('shift_type_id', ShiftType::query()->where('code', 'weekday_day')->sole()->id)->update(['main_count' => 2]);
            break;
    }
    $before = updatedMonthlySetupSavedState();

    $this->actingAs($admin)->post(route('rosters.recreate-updated-setup', ['year' => 2026, 'month' => 11]), $payload)->assertSessionHasErrors($field);

    expect(updatedMonthlySetupSavedState())->toEqual($before);
})->with([
    ['final', 'roster'], ['previously final', 'roster'], ['actual work', 'roster'], ['actual work exception', 'roster'], ['later roster', 'roster'],
    ['history', 'roster'], ['inactive', 'roster'], ['nonparticipating', 'doctor_id'], ['missing participation', 'roster'],
    ['snapshot', 'roster'], ['missing shift', 'roster'], ['incompatible rotation', 'configuration'],
    ['no exclusion', 'doctor_id'], ['changed assignments', 'revision'], ['changed capacity', 'roster'], ['mismatched weekday Main', 'roster'],
]);

it('requires authentication for updated setup recreation', function () {
    $this->post(route('rosters.recreate-updated-setup', ['year' => 2026, 'month' => 11]), [])->assertRedirect(route('login'));
});

it('provides reviewed exclusions and saved capacity only for an eligible Draft', function () {
    ['admin' => $admin, 'roster' => $roster, 'doctor' => $doctor] = updatedMonthlySetupFixture();
    $this->withoutVite();
    $before = updatedMonthlySetupSavedState();

    $this->actingAs($admin)->get(route('rosters.show', ['year' => 2026, 'month' => 11]))
        ->assertInertia(fn (Assert $page) => $page->component('roster')
            ->where('monthly_setup_recreation.current_optional_count', 2)
            ->has('monthly_setup_recreation.exclusions', 2)
            ->where('monthly_setup_recreation.exclusions.1.id', $doctor->id)
            ->where('monthly_setup_recreation.revision', fn ($value): bool => is_string($value) && strlen($value) === 64));

    expect(updatedMonthlySetupSavedState())->toEqual($before);
    $roster->update(['status' => RosterStatus::Final, 'finalized_at' => now()]);
    $this->get(route('rosters.show', ['year' => 2026, 'month' => 11]))
        ->assertInertia(fn (Assert $page) => $page->where('monthly_setup_recreation', null));
});

it('rejects updated setup recreation outside the planning period at both request and service boundaries', function () {
    ['admin' => $admin, 'roster' => $roster, 'doctor' => $doctor, 'payload' => $payload] = updatedMonthlySetupFixture();
    $this->travelTo(new DateTimeImmutable('2026-12-01 09:00:00'));
    config(['roster.uat_operational_month' => null]);
    $before = updatedMonthlySetupSavedState();

    $this->actingAs($admin)->post(route('rosters.recreate-updated-setup', ['year' => 2026, 'month' => 11]), $payload)->assertRedirect(route('dashboard'));
    expect(fn () => app(RecreateDraftRoster::class)->handleUpdatedSetup($roster, $admin, $doctor->id, 3, true, $payload['revision']))->toThrow(ValidationException::class);

    expect(updatedMonthlySetupSavedState())->toEqual($before);
});

it('requires the existing coordination lock before changing updated monthly setup', function () {
    ['admin' => $admin, 'roster' => $roster, 'doctor' => $doctor, 'payload' => $payload] = updatedMonthlySetupFixture();
    DB::table('weekend_pair_coordination')->where('id', 1)->delete();
    $before = updatedMonthlySetupSavedState();

    expect(fn () => app(RecreateDraftRoster::class)->handleUpdatedSetup($roster, $admin, $doctor->id, 3, true, $payload['revision']))
        ->toThrow(LogicException::class, 'The weekend pair coordination row is missing.');

    expect(updatedMonthlySetupSavedState())->toEqual($before);
});

it('keeps ordinary pair confirmation read only after a Draft exists', function () {
    ['admin' => $admin, 'roster' => $roster, 'groups' => $groups] = updatedMonthlySetupFixture();
    $service = app(MonthlyWeekendPairService::class);
    $state = $service->load(2026, 11);
    $input = ['revision' => $state['revision'], 'groups' => []];
    foreach (['A', 'B'] as $group) {
        $input['groups'][$group] = ['effective_saturday' => $state['groups'][$group]['effective_saturday'], 'doctor_ids' => $groups[$group]];
    }
    $before = updatedMonthlySetupSavedState();

    expect(fn () => $service->confirm(2026, 11, $input, $admin->id))->toThrow(ValidationException::class);

    expect(updatedMonthlySetupSavedState())->toEqual($before);
});

it('rejects updated setup when the saved rotation uses seven doctors', function () {
    ['admin' => $admin] = mandatoryWeekendFixture(true);
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $doctor = Doctor::query()->where('short_code', 'U')->sole();
    $before = updatedMonthlySetupSavedState();

    expect(fn () => app(RecreateDraftRoster::class)->handleUpdatedSetup($roster, $admin, $doctor->id, 3, true, str_repeat('0', 64)))
        ->toThrow(ValidationException::class);

    expect(app(RecreateDraftRoster::class)->monthlySetupReview($roster))->toBeNull();
    expect(updatedMonthlySetupSavedState())->toEqual($before);
});

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

it('keeps an unselected participant in ordinary weekday Main and weekend Optional without displacing mandatory Main', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $extra = Doctor::query()->create(['name' => 'Ordinary participant', 'short_code' => 'B7', 'is_active' => true]);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $extra->id, 'group_code' => 'B', 'effective_from_saturday' => '2026-10-03']);
    mandatoryWeekendConfirm($admin, $groups);
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $weekday = mandatoryWeekendShift($roster, '2026-11-03', 'weekday_day')->assignments()
        ->create(['doctor_id' => $extra->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $optional = mandatoryWeekendShift($roster, '2026-11-07')->assignments()
        ->create(['doctor_id' => $extra->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $snapshots = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    app(RosterAssignmentGenerator::class)->generate($roster, $admin);

    $this->assertModelExists($weekday);
    $this->assertModelExists($optional);
    $this->assertDatabaseHas('doctor_monthly_participations', ['roster_id' => $roster->id, 'doctor_id' => $extra->id, 'is_participating' => true]);
    foreach (['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'] as $saturday) {
        $plan = app(WeekendPairRotationService::class)->forSaturday($saturday);
        foreach (['saturday' => $saturday, 'sunday' => CarbonImmutable::parse($saturday)->addDay()->toDateString()] as $day => $date) {
            foreach (['day', 'night'] as $duty) {
                $actual = mandatoryWeekendMainIds($roster, $date, 'weekend_'.$duty);
                expect($actual)->toBe($plan['shifts'][$day.'_'.$duty]);
                expect($actual)->not->toContain($extra->id);
            }
        }
    }
    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($snapshots);
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

/** @return array{admin: User, groups: array<string, list<int>>, july: Roster, august: Roster, plan: array<string, mixed>, extraId: int|null} */
function mandatoryWeekendBoundaryFixture(bool $seven = false, bool $extraParticipant = false): array
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
    $extraId = null;
    if ($extraParticipant) {
        $extra = Doctor::query()->create(['name' => 'Ordinary boundary participant', 'short_code' => 'B8', 'is_active' => true]);
        DoctorWeekendGroupMembership::query()->create(['doctor_id' => $extra->id, 'group_code' => 'B', 'effective_from_saturday' => '2027-07-31']);
        $extraId = $extra->id;
    }
    $groups = array_map('array_reverse', $groups);
    mandatoryWeekendConfirm($admin, $groups, 2027, 8);
    $august = app(RosterStructureService::class)->create(2027, 8, $admin);

    return compact('admin', 'groups', 'july', 'august', 'plan', 'extraId');
}

it('generates the historical Sunday pairs with an additional ordinary participant in the new month', function () {
    ['admin' => $admin, 'july' => $july, 'august' => $august, 'plan' => $plan, 'extraId' => $extraId] = mandatoryWeekendBoundaryFixture(extraParticipant: true);
    $before = $july->shifts()->with(['assignments', 'capacitySnapshot'])->get()->toArray();

    app(RosterAssignmentGenerator::class)->generate($august, $admin);

    expect(mandatoryWeekendMainIds($august, '2027-08-01'))->toBe($plan['shifts']['sunday_day']);
    expect(mandatoryWeekendMainIds($august, '2027-08-01', 'weekend_night'))->toBe($plan['shifts']['sunday_night']);
    expect($july->shifts()->with(['assignments', 'capacitySnapshot'])->get()->toArray())->toBe($before);
    $this->assertDatabaseHas('doctor_monthly_participations', ['roster_id' => $august->id, 'doctor_id' => $extraId, 'is_participating' => true]);
    assertMandatoryWeekendSafe($august);
});

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

it('recreates the November Draft with two legacy Main slots and preserves monthly inputs and October Final', function (bool $seven) {
    ['admin' => $admin, 'groups' => $groups, 'october' => $october] = mandatoryWeekendFixture($seven);
    $nightType = ShiftType::query()->where('code', 'weekday_night')->sole();
    foreach ([5, 12, 19, 26] as $day) {
        DoctorRequest::query()->create([
            'doctor_id' => $groups['A'][0], 'request_type' => DoctorRequestType::PreferredWork,
            'request_date' => sprintf('2026-11-%02d', $day), 'shift_type_id' => $nightType->id,
        ]);
    }
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);
    $oldShiftIds = $roster->shifts()->pluck('id')->all();
    $octoberBefore = $october->fresh()->toArray();
    $octoberAssignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->orderBy('id')->get()->toArray();
    $octoberSnapshots = RosterShiftCapacitySnapshot::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->orderBy('id')->get()->toArray();
    $participation = DoctorMonthlyParticipation::query()->orderBy('id')->get()->toArray();
    $confirmations = MonthlyWeekendPairConfirmation::query()->orderBy('id')->get()->toArray();
    $versions = WeekendPairConfiguration::query()->orderBy('id')->get()->toArray();
    $requests = DoctorRequest::query()->orderBy('id')->get()->toArray();
    $exclusions = DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray();
    $saved = $roster->shifts()->with(['shiftType', 'capacitySnapshot'])->get()->mapWithKeys(fn (RosterShift $shift): array => [
        $shift->shift_date->toDateString().':'.$shift->shiftType->code => [$shift->savedCapacity()->main_count, $shift->savedCapacity()->optional_count],
    ])->all();
    expect(mandatoryWeekendShift($roster, '2026-11-01')->savedCapacity()->main_count)->toBe(3);

    $this->actingAs($admin)->post(route('rosters.recreate', ['year' => 2026, 'month' => 11]), [
        'legacy_main_count' => 2, 'confirmation' => true,
    ])->assertSessionHasNoErrors()->assertRedirect(route('rosters.show', ['year' => 2026, 'month' => 11]));

    expect($roster->fresh()->status)->toBe(RosterStatus::Draft);
    expect($roster->fresh()->updated_by)->toBe($admin->id);
    expect($roster->fresh()->last_generated_at)->not->toBeNull();
    expect($roster->shifts()->whereIn('id', $oldShiftIds)->exists())->toBeFalse();
    expect(RosterShiftCapacitySnapshot::query()->whereIn('roster_shift_id', $oldShiftIds)->exists())->toBeFalse();
    foreach ($roster->shifts()->with(['shiftType', 'capacitySnapshot'])->get() as $shift) {
        $key = $shift->shift_date->toDateString().':'.$shift->shiftType->code;
        $expected = $key === '2026-11-01:weekend_day' ? [2, 7] : $saved[$key];
        expect([$shift->savedCapacity()->main_count, $shift->savedCapacity()->optional_count])->toBe($expected);
    }
    expect($roster->shifts()->with('capacitySnapshot')->get()->sum(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count))->toBe($seven ? 191 : 183);
    expect(app(MonthlyShiftCapacityService::class)->resolve(2026, 11, 'weekend_day')['main_count'])->toBe(2);
    expect(app(MonthlyShiftCapacityService::class)->resolve(2026, 12, 'weekend_day')['main_count'])->toBe(3);
    expect(DoctorMonthlyParticipation::query()->orderBy('id')->get()->toArray())->toBe($participation);
    expect(MonthlyWeekendPairConfirmation::query()->orderBy('id')->get()->toArray())->toBe($confirmations);
    expect(WeekendPairConfiguration::query()->orderBy('id')->get()->toArray())->toBe($versions);
    expect(DoctorRequest::query()->orderBy('id')->get()->toArray())->toBe($requests);
    expect(DoctorMonthlyExclusion::query()->orderBy('id')->get()->toArray())->toBe($exclusions);
    expect($october->fresh()->toArray())->toBe($octoberBefore);
    expect(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->orderBy('id')->get()->toArray())->toBe($octoberAssignments);
    expect(RosterShiftCapacitySnapshot::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->orderBy('id')->get()->toArray())->toBe($octoberSnapshots);
    $firstSundayIds = $roster->shifts()->whereDate('shift_date', '2026-11-01')->get()->flatMap(fn (RosterShift $shift) => $shift->assignments()->pluck('doctor_id'))->all();
    expect(array_intersect($firstSundayIds, array_slice($groups['A'], 0, 2)))->toBe([]);
    foreach (['weekend_day', 'weekend_night'] as $code) {
        expect(mandatoryWeekendMainIds($roster, '2026-11-01', $code))->toHaveCount(2);
        expect(array_diff(mandatoryWeekendMainIds($roster, '2026-11-01', $code), $groups['A']))->toBe([]);
    }
    foreach (['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'] as $saturday) {
        $plan = app(WeekendPairRotationService::class)->forSaturday($saturday);
        expect(mandatoryWeekendMainIds($roster, $saturday))->toBe($plan['shifts']['saturday_day']);
        expect(mandatoryWeekendMainIds($roster, $saturday, 'weekend_night'))->toBe($plan['shifts']['saturday_night']);
        expect(mandatoryWeekendMainIds($roster, $plan['sunday']))->toBe($plan['shifts']['sunday_day']);
        expect(mandatoryWeekendMainIds($roster, $plan['sunday'], 'weekend_night'))->toBe($plan['shifts']['sunday_night']);
    }
    assertMandatoryWeekendSafe($roster);
})->with(['six doctors' => false, 'seven doctors' => true]);

it('allows legacy cross group fallback after recreation when Group A cannot fill both Night slots', function () {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $night = ShiftType::query()->where('code', 'weekend_night')->sole();
    foreach (array_slice($groups['A'], 2) as $id) {
        DoctorRequest::query()->create(['doctor_id' => $id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-01', 'shift_type_id' => $night->id]);
    }

    app(RecreateDraftRoster::class)->handle($roster, $admin, 2, true);

    $ids = mandatoryWeekendMainIds($roster, '2026-11-01', 'weekend_night');
    expect($ids)->toHaveCount(2);
    expect(array_intersect($ids, $groups['B']))->toHaveCount(2);
    expect(collect(app(RosterDraftValidationService::class)->validate($roster->fresh()))->where('code', 'weekend_group_exception'))->not->toBeEmpty();
    assertMandatoryWeekendSafe($roster);
});

it('rejects unconfirmed or invalid Draft recreation without changing snapshots', function (array $input, string $error) {
    ['admin' => $admin] = mandatoryWeekendFixture();
    app(RosterStructureService::class)->create(2026, 11, $admin);
    $before = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    $this->actingAs($admin)->post(route('rosters.recreate', ['year' => 2026, 'month' => 11]), $input)->assertSessionHasErrors($error);

    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect(app(MonthlyShiftCapacityService::class)->resolve(2026, 11, 'weekend_day')['main_count'])->toBe(3);
})->with([
    'missing confirmation' => [['legacy_main_count' => 2], 'confirmation'],
    'declined confirmation' => [['legacy_main_count' => 2, 'confirmation' => false], 'confirmation'],
    'negative capacity' => [['legacy_main_count' => -1, 'confirmation' => true], 'legacy_main_count'],
    'fractional capacity' => [['legacy_main_count' => 2.5, 'confirmation' => true], 'legacy_main_count'],
    'too large' => [['legacy_main_count' => 256, 'confirmation' => true], 'legacy_main_count'],
]);

it('requires authentication to recreate a Draft', function () {
    $this->post(route('rosters.recreate', ['year' => 2026, 'month' => 11]), ['legacy_main_count' => 2, 'confirmation' => true])
        ->assertRedirect(route('login'));
});

it('protects Final and previously finalized Draft rosters from recreation', function (RosterStatus $status) {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $roster->update(['status' => $status, 'finalized_at' => now()]);
    $before = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    $this->actingAs($admin)->post(route('rosters.recreate', ['year' => 2026, 'month' => 11]), ['legacy_main_count' => 2, 'confirmation' => true])
        ->assertSessionHasErrors('roster');

    expect($roster->fresh()->status)->toBe($status);
    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect(app(MonthlyShiftCapacityService::class)->resolve(2026, 11, 'weekend_day')['main_count'])->toBe(3);
})->with([RosterStatus::Final, RosterStatus::Draft]);

it('rolls back the entire Draft recreation when snapshots generation or pair integrity fail', function (string $failure) {
    ['admin' => $admin, 'groups' => $groups] = mandatoryWeekendFixture();
    $roster = app(GenerateRosterFromMonthlySetup::class)->handle(2026, 11, $admin);
    $enabled = true;
    $created = 0;
    if ($failure === 'stale pairs') {
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['B'][0], 'year' => 2026, 'month' => 11]);
    } elseif ($failure === 'mandatory conflict') {
        DoctorRequest::query()->create(['doctor_id' => $groups['B'][0], 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-07']);
    } else {
        $model = $failure === 'snapshots' ? RosterShiftCapacitySnapshot::class : RosterAssignment::class;
        Event::listen('eloquent.creating: '.$model, function () use (&$enabled, &$created): void {
            if ($enabled && ++$created === 4) {
                throw new RuntimeException('Simulated recreation failure.');
            }
        });
    }
    $tables = ['rosters', 'roster_shifts', 'roster_shift_capacity_snapshots', 'roster_assignments', 'doctor_monthly_participations', 'monthly_shift_capacities', 'monthly_weekend_pair_confirmations', 'doctor_requests', 'doctor_monthly_exclusions'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }

    try {
        expect(fn () => app(RecreateDraftRoster::class)->handle($roster, $admin, 2, true))
            ->toThrow(in_array($failure, ['stale pairs', 'mandatory conflict'], true) ? ValidationException::class : RuntimeException::class);
    } finally {
        $enabled = false;
    }

    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($before[$table]);
    }
})->with(['snapshots', 'assignments', 'stale pairs', 'mandatory conflict']);

it('refuses recreation when actual work or a later dependent roster exists', function (string $history) {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    if ($history === 'actual work') {
        $roster->update(['actual_work_confirmed_at' => now()]);
    } else {
        Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => RosterStatus::Draft]);
    }
    $before = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();

    expect(fn () => app(RecreateDraftRoster::class)->handle($roster, $admin, 2, true))->toThrow(ValidationException::class);

    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect(app(MonthlyShiftCapacityService::class)->resolve(2026, 11, 'weekend_day')['main_count'])->toBe(3);
})->with(['actual work', 'later roster']);

it('refuses recreation outside the current planning period without changing the Draft', function () {
    ['admin' => $admin] = mandatoryWeekendFixture();
    $roster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $before = RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray();
    $this->travelTo(new DateTimeImmutable('2026-12-01 09:00:00'));
    config(['roster.uat_operational_month' => null]);

    expect(fn () => app(RecreateDraftRoster::class)->handle($roster, $admin, 2, true))->toThrow(ValidationException::class);

    expect(RosterShiftCapacitySnapshot::query()->orderBy('id')->get()->toArray())->toBe($before);
});
