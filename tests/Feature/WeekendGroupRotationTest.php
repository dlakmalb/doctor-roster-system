<?php

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\RosterAssignment;
use App\Models\User;
use App\Models\WeekendRotationConfiguration;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterAssignmentRecoveryService;
use App\Services\RosterCandidateRanker;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use App\Services\WeekendGroupRotationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\October2026WeekendRotationSeeder;
use Database\Seeders\ShiftTypesSeeder;

function seedWeekendRotationGroups(string $effectiveFrom = '2026-09-26'): void
{
    $groups = [
        'H' => 'A', 'T' => 'A', 'I' => 'A', 'A' => 'A', 'L' => 'A', 'R' => 'A', 'B' => 'A',
        'N' => 'B', 'S' => 'B', 'K' => 'B', 'E' => 'B', 'G' => 'B', 'M' => 'B', 'U' => 'B',
    ];
    foreach ($groups as $shortCode => $group) {
        DoctorWeekendGroupMembership::query()->create([
            'doctor_id' => Doctor::query()->where('short_code', $shortCode)->value('id'),
            'group_code' => $group,
            'effective_from_saturday' => $effectiveFrom,
        ]);
    }
    DoctorWeekendGroupMembership::query()->create([
        'doctor_id' => Doctor::query()->where('short_code', 'C')->value('id'),
        'group_code' => 'A',
        'effective_from_saturday' => '2026-10-03',
    ]);
}

it('continues the alternating rotation across month boundaries from its Saturday anchor', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    seedWeekendRotationGroups();
    $admin = User::factory()->create();
    $october = app(RosterStructureService::class)->create(2026, 10, $admin);
    $doctors = Doctor::query()->where('is_active', true)->get();
    $rotation = app(WeekendGroupRotationService::class)->forMonth(2026, 10, $doctors, $october->shifts()->with('shiftType')->get());
    $november = app(RosterStructureService::class)->create(2026, 11, $admin);
    $novemberRotation = app(WeekendGroupRotationService::class)->forMonth(2026, 11, $doctors, $november->shifts()->with('shiftType')->get());
    $configuration = WeekendRotationConfiguration::query()->firstOrFail();

    expect($rotation['error'])->toBeNull()
        ->and($rotation['expected'])->toMatchArray([
            '2026-10-03' => 'A',
            '2026-10-10' => 'B',
            '2026-10-17' => 'A',
            '2026-10-24' => 'B',
            '2026-10-31' => 'A',
        ])
        ->and(app(WeekendGroupRotationService::class)->scheduledGroupFor($configuration, CarbonImmutable::parse('2026-09-26')))->toBe('B')
        ->and($novemberRotation['expected'])->toMatchArray(['2026-10-31' => 'A']);
});

it('uses effective membership changes without changing earlier weekend membership', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    seedWeekendRotationGroups();
    $doctor = Doctor::query()->where('short_code', 'T')->firstOrFail();
    DoctorWeekendGroupMembership::query()->create([
        'doctor_id' => $doctor->id,
        'group_code' => 'B',
        'effective_from_saturday' => '2026-10-17',
    ]);
    $roster = app(RosterStructureService::class)->create(2026, 10, User::factory()->create());
    $rotation = app(WeekendGroupRotationService::class)->forMonth(2026, 10, collect([$doctor]), $roster->shifts()->with('shiftType')->get());

    expect(app(WeekendGroupRotationService::class)->groupFor($rotation['assignments'], $doctor->id, '2026-10-10'))->toBe('A')
        ->and(app(WeekendGroupRotationService::class)->groupFor($rotation['assignments'], $doctor->id, '2026-10-17'))->toBe('B');
});

it('prioritizes scheduled group membership ahead of workload fairness and leaves Optional ranking unchanged', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    seedWeekendRotationGroups();
    $groupA = Doctor::query()->where('short_code', 'T')->firstOrFail();
    $groupB = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $roster = app(RosterStructureService::class)->create(2026, 10, User::factory()->create());
    $shifts = $roster->shifts()->with(['shiftType', 'assignments'])->get();
    $rotation = app(WeekendGroupRotationService::class)->forMonth(2026, 10, collect([$groupA, $groupB]), $shifts);
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize($shifts, collect(), collect(), CarbonImmutable::parse('2026-10-01'), [], null, $rotation);
    $saturday = $shifts->first(fn ($shift): bool => $shift->shift_date->toDateString() === '2026-10-03' && $shift->shiftType->code === 'weekend_day');

    expect($ranker->ordered(collect([$groupB, $groupA]), $saturday, RosterAssignmentRole::Main, collect())->first()->id)->toBe($groupA->id)
        ->and($ranker->dimensions($groupA, $saturday, RosterAssignmentRole::Optional, collect()))
        ->toBe($ranker->dimensions($groupB, $saturday, RosterAssignmentRole::Optional, collect()));
});

it('uses the opposite group only after the scheduled group cannot fill a mandatory slot', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    $groupA = Doctor::query()->where('short_code', 'T')->firstOrFail();
    $groupB = Doctor::query()->where('short_code', 'N')->firstOrFail();
    foreach ([[$groupA, 'A'], [$groupB, 'B']] as [$doctor, $group]) {
        DoctorWeekendGroupMembership::query()->create([
            'doctor_id' => $doctor->id,
            'group_code' => $group,
            'effective_from_saturday' => '2026-09-26',
        ]);
    }
    $roster = app(RosterStructureService::class)->create(2026, 10, User::factory()->create());
    $weekendDay = $roster->shifts()->whereDate('shift_date', '2026-10-03')->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->with(['shiftType', 'assignments'])->firstOrFail();
    $shifts = collect([$weekendDay]);
    $rotation = app(WeekendGroupRotationService::class)->forMonth(2026, 10, collect([$groupA, $groupB]), $roster->shifts()->with('shiftType')->get());
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize($shifts, collect(), collect(), CarbonImmutable::parse('2026-10-01'), [], null, $rotation);
    $assignments = app(RosterAssignmentRecoveryService::class)->plan(
        $shifts,
        collect([$groupA, $groupB]),
        collect(),
        collect(),
        collect(),
        $ranker,
    );

    expect(collect($assignments)->pluck('doctor_id')->all())->toBe([$groupA->id, $groupB->id]);
});

it('generates an October roster with all Main positions covered using the configured rotation', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $this->seed(October2026WeekendRotationSeeder::class);

    $startedAt = microtime(true);
    app(RosterAssignmentGenerator::class)->generate($roster, $admin);
    $durationSeconds = microtime(true) - $startedAt;
    $mainPositions = $roster->shifts()->with('shiftType')->get()->sum(fn ($shift): int => $shift->shiftType->main_count);
    $assignedMain = RosterAssignment::query()->whereIn('roster_shift_id', $roster->shifts()->select('id'))->where('role', RosterAssignmentRole::Main->value)->count();
    $rotationWarnings = collect(app(RosterDraftValidationService::class)->validate($roster))
        ->where('code', 'weekend_group_exception');

    expect($assignedMain)->toBe($mainPositions)
        ->and($rotationWarnings->every(fn (array $warning): bool => $warning['severity'] === 'Warning'))->toBeTrue()
        ->and($durationSeconds)->toBeGreaterThan(0);
});

it('shows monthly group membership history and the scheduled weekend list', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    seedWeekendRotationGroups();

    $this->actingAs(User::factory()->create())
        ->get(route('weekend-groups.show', ['year' => 2026, 'month' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('weekend-groups')
            ->has('memberships')
            ->has('membershipHistory')
            ->where('configurationError', null)
            ->where('weekends.0.group', 'A')
            ->where('weekends.4.label', 'Oct 31–Nov 1'));
});

it('validates management coverage for each selected-month weekend', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    seedWeekendRotationGroups();
    $doctor = Doctor::query()->where('short_code', 'T')->firstOrFail();
    DoctorWeekendGroupMembership::query()->create([
        'doctor_id' => $doctor->id,
        'group_code' => null,
        'effective_from_saturday' => '2026-10-10',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('weekend-groups.show', ['year' => 2026, 'month' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('configurationError', 'Dr Thisara has no valid Group A or Group B membership for the weekend of 2026-10-10.'));
});

it('validates historical rotation against saved participation after doctor status and membership changes', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    $hirushini = Doctor::query()->where('short_code', 'H')->firstOrFail();
    $umanga = Doctor::query()->where('short_code', 'C')->firstOrFail();
    DoctorWeekendGroupMembership::query()->create([
        'doctor_id' => $hirushini->id,
        'group_code' => 'A',
        'effective_from_saturday' => '2026-08-29',
    ]);
    DoctorWeekendGroupMembership::query()->create([
        'doctor_id' => $hirushini->id,
        'group_code' => null,
        'effective_from_saturday' => '2026-10-03',
    ]);
    DoctorWeekendGroupMembership::query()->create([
        'doctor_id' => $umanga->id,
        'group_code' => 'A',
        'effective_from_saturday' => '2026-10-03',
    ]);
    $roster = app(RosterStructureService::class)->create(2026, 9, User::factory()->create());
    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->update(['is_participating' => false]);
    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $hirushini->id)->update(['is_participating' => true]);
    $roster->update(['status' => RosterStatus::Final]);

    $hirushini->update(['is_active' => false]);
    $umanga->update(['is_active' => true]);

    $items = collect(app(RosterDraftValidationService::class)->validate($roster->fresh()));
    expect($items->contains(fn (array $item): bool => $item['code'] === 'weekend_group_configuration'))->toBeFalse()
        ->and($items->contains(fn (array $item): bool => $item['code'] === 'participation_snapshot_integrity'))->toBeFalse()
        ->and($items->contains(fn (array $item): bool => $item['code'] === 'participation_population_mismatch'))->toBeFalse();
});

it('records a future membership change with the acting administrator and protects finalized rosters', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    seedWeekendRotationGroups();
    $admin = User::factory()->create();
    $doctor = Doctor::query()->where('short_code', 'K')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('weekend-groups.store', ['year' => 2026, 'month' => 10]), [
            'doctor_id' => $doctor->id,
            'group_code' => 'A',
            'effective_from_saturday' => '2026-11-07',
        ])
        ->assertRedirect();

    $membership = DoctorWeekendGroupMembership::query()
        ->where('doctor_id', $doctor->id)
        ->whereDate('effective_from_saturday', '2026-11-07')
        ->firstOrFail();
    expect($membership->group_code)->toBe('A')->and($membership->changed_by)->toBe($admin->id);

    $finalRoster = app(RosterStructureService::class)->create(2026, 11, $admin);
    $finalRoster->update(['status' => RosterStatus::Final]);
    $this->post(route('weekend-groups.store', ['year' => 2026, 'month' => 10]), [
        'doctor_id' => $doctor->id,
        'group_code' => 'B',
        'effective_from_saturday' => '2026-11-14',
    ])->assertSessionHasErrors('effective_from_saturday');
    expect(DoctorWeekendGroupMembership::query()->where('doctor_id', $doctor->id)->whereDate('effective_from_saturday', '2026-11-14')->exists())->toBeFalse();
});

it('seeds October groups idempotently without changing doctor status or existing draft assignments', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $statuses = Doctor::query()->pluck('is_active', 'id')->all();
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $shift = $roster->shifts()->whereDate('shift_date', '2026-10-03')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->firstOrFail();
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $assignment = RosterAssignment::query()->create([
        'roster_shift_id' => $shift->id,
        'doctor_id' => $doctor->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);

    $this->seed(October2026WeekendRotationSeeder::class);
    $this->seed(October2026WeekendRotationSeeder::class);
    $memberships = DoctorWeekendGroupMembership::query()->whereDate('effective_from_saturday', '<=', '2026-10-03')->orderBy('effective_from_saturday')->get()->groupBy('doctor_id');
    $activeGroups = Doctor::query()->where('is_active', true)->get()->groupBy(fn (Doctor $activeDoctor): ?string => app(WeekendGroupRotationService::class)->groupFor($memberships->all(), $activeDoctor->id, '2026-10-03'));

    expect(Doctor::query()->pluck('is_active', 'id')->all())->toBe($statuses)
        ->and(RosterAssignment::query()->findOrFail($assignment->id)->doctor_id)->toBe($doctor->id)
        ->and(WeekendRotationConfiguration::query()->first()->anchor_saturday->toDateString())->toBe('2026-09-26')
        ->and(DoctorWeekendGroupMembership::query()->where('doctor_id', Doctor::query()->where('short_code', 'C')->value('id'))->whereDate('effective_from_saturday', '2026-10-03')->value('group_code'))->toBe('A')
        ->and($activeGroups->get('A')->count())->toBe(7)
        ->and($activeGroups->get('B')->count())->toBe(7)
        ->and(DoctorWeekendGroupMembership::query()->where('doctor_id', Doctor::query()->where('short_code', 'H')->value('id'))->whereDate('effective_from_saturday', '2026-10-03')->value('group_code'))->toBeNull()
        ->and(Doctor::query()->where('short_code', 'H')->value('is_active'))->toBeFalse();
});

it('replaces repeated-weekend warnings with informational cross-group exceptions', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-09-26', 'anchor_group' => 'B']);
    seedWeekendRotationGroups();
    $roster = app(RosterStructureService::class)->create(2026, 10, User::factory()->create());
    $saturdayDay = $roster->shifts()->whereDate('shift_date', '2026-10-03')->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->firstOrFail();
    $sundayDay = $roster->shifts()->whereDate('shift_date', '2026-10-04')->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->firstOrFail();
    $inGroup = Doctor::query()->where('short_code', 'T')->firstOrFail();
    $exception = Doctor::query()->where('short_code', 'N')->firstOrFail();
    foreach ([[$saturdayDay, $inGroup, 1], [$sundayDay, $inGroup, 1], [$sundayDay, $exception, 2]] as [$shift, $doctor, $slot]) {
        RosterAssignment::query()->create([
            'roster_shift_id' => $shift->id,
            'doctor_id' => $doctor->id,
            'role' => RosterAssignmentRole::Main,
            'slot_number' => $slot,
        ]);
    }

    $warnings = collect(app(RosterDraftValidationService::class)->validate($roster))->where('severity', 'Warning');

    expect($warnings->contains(fn (array $warning): bool => $warning['code'] === 'multiple_weekend_main'))->toBeFalse()
        ->and($warnings->first(fn (array $warning): bool => $warning['code'] === 'weekend_group_exception')['message'])
        ->toContain('Dr Nuwan (MOIC) (Group B)', "Group A's scheduled weekend");
});
