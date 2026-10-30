<?php

use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\Roster;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WeekendPairConfiguration;
use App\Models\WeekendRotationConfiguration;
use App\Services\WeekendGroupRotationService;
use App\Services\WeekendPairConfigurationService;
use App\Services\WeekendPairRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function pairFixture(string $group, string $effective, int $mode = 6): array
{
    $ids = [];
    for ($i = 1; $i <= $mode; $i++) {
        $doctor = Doctor::query()->create(['name' => "$group doctor $i", 'short_code' => "$group$i", 'is_active' => true]);
        DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => $group, 'effective_from_saturday' => $effective]);
        $ids[] = $doctor->id;
    }

    return $ids;
}

function pairInput(string $group, string $effective, array $ids): array
{
    return ['group_code' => $group, 'effective_saturday' => $effective, 'mode' => count($ids), 'doctor_ids' => $ids, 'pair_anchor_phase' => 0, 'extra_day_anchor_phase' => 0];
}

function pairAnchor(): void
{
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-11-07', 'anchor_group' => 'B']);
}

it('resolves the independent November and December reference rotations and leaves the earlier weekend legacy', function () {
    pairAnchor();
    $admin = User::factory()->create();
    $b = pairFixture('B', '2026-11-07');
    $a = pairFixture('A', '2026-11-14');
    $service = app(WeekendPairConfigurationService::class);
    $service->create(pairInput('B', '2026-11-07', $b), $admin->id);
    $service->create(pairInput('A', '2026-11-14', $a), $admin->id);
    $calculator = app(WeekendPairRotationService::class);

    expect($calculator->forSaturday('2026-10-31'))->toBeNull();
    expect($calculator->forSaturday('2026-10-24'))->toBeNull();
    foreach ([['2026-11-07', 'B', $b, 1], ['2026-11-14', 'A', $a, 1], ['2026-11-21', 'B', $b, 2], ['2026-11-28', 'A', $a, 2], ['2026-12-05', 'B', $b, 3], ['2026-12-12', 'A', $a, 3]] as [$date, $group, $ids, $pair]) {
        $result = $calculator->forSaturday($date);
        $pairs = array_chunk($ids, 2);
        expect($result['group_code'])->toBe($group)
            ->and($result['starting_pair'])->toBe($pair)
            ->and($result['shifts'])->toBe(['saturday_day' => $pairs[$pair - 1], 'saturday_night' => $pairs[$pair % 3], 'sunday_day' => $pairs[($pair + 1) % 3], 'sunday_night' => $pairs[$pair - 1]]);
    }
    expect($calculator->forSaturday('2027-01-02')['starting_pair'])->toBe(2);
    expect(app(WeekendGroupRotationService::class)->scheduledGroupFor(WeekendRotationConfiguration::query()->firstOrFail(), CarbonImmutable::parse('2026-10-31')))->toBe('A');
});

it('calculates the exact seven doctor rotation and repeats the complete pattern after 21 occurrences', function () {
    pairAnchor();
    $admin = User::factory()->create();
    $ids = pairFixture('B', '2026-11-07', 7);
    app(WeekendPairConfigurationService::class)->create(pairInput('B', '2026-11-07', $ids), $admin->id);
    $calculator = app(WeekendPairRotationService::class);
    $first = $calculator->forSaturday('2026-11-07');
    $second = $calculator->forSaturday('2026-11-21');

    expect($first['shifts'])->toBe(['saturday_day' => [$ids[0], $ids[1], $ids[6]], 'saturday_night' => [$ids[2], $ids[3]], 'sunday_day' => [$ids[4], $ids[5], $ids[6]], 'sunday_night' => [$ids[0], $ids[1]]]);
    expect($second['shifts'])->toBe(['saturday_day' => [$ids[2], $ids[3], $ids[0]], 'saturday_night' => [$ids[4], $ids[5]], 'sunday_day' => [$ids[6], $ids[1], $ids[0]], 'sunday_night' => [$ids[2], $ids[3]]]);
    $extra = [];
    $patterns = [];
    for ($i = 0; $i < 21; $i++) {
        $date = CarbonImmutable::parse('2026-11-07')->addDays(14 * $i)->toDateString();
        $result = $calculator->forSaturday($date);
        $extra[] = $result['additional_day_doctor_id'];
        $patterns[] = json_encode($result['shifts']);
        expect($calculator->forSaturday($date))->toBe($result);
        expect(array_diff($result['shifts']['sunday_night'], $result['shifts']['saturday_day']))->toBe([]);
        foreach ([['saturday_day', 'saturday_night'], ['sunday_day', 'sunday_night']] as [$day, $night]) {
            $assigned = array_merge($result['shifts'][$day], $result['shifts'][$night]);
            expect(count(array_unique($assigned)))->toBe(count($assigned));
        }
    }
    expect(array_slice($extra, 0, 7))->toBe([$ids[6], $ids[0], $ids[1], $ids[2], $ids[3], $ids[4], $ids[5]])
        ->and(count(array_unique($patterns)))->toBe(21)
        ->and($calculator->forSaturday('2027-08-28')['shifts'])->toBe($first['shifts']);
});

it('preserves pair phase through both mode transitions and across a split month weekend', function () {
    pairAnchor();
    $admin = User::factory()->create();
    $ids = pairFixture('B', '2026-11-07', 7);
    $service = app(WeekendPairConfigurationService::class);
    $first = $service->create(pairInput('B', '2026-11-07', array_slice($ids, 0, 6)), $admin->id);
    $second = $service->create(pairInput('B', '2026-11-21', $ids), $admin->id);
    $third = $service->create(pairInput('B', '2026-12-05', array_slice($ids, 0, 6)), $admin->id);
    $calculator = app(WeekendPairRotationService::class);

    expect([$first->version, $second->version, $third->version])->toBe([1, 2, 3]);
    expect($second->pair_anchor_saturday->toDateString())->toBe('2026-11-07')
        ->and($third->pair_anchor_saturday->toDateString())->toBe('2026-11-07');
    expect($calculator->forSaturday('2026-11-07')['configuration_id'])->toBe($first->id)
        ->and($calculator->forSaturday('2026-11-21')['configuration_id'])->toBe($second->id)
        ->and($calculator->forSaturday('2026-12-05')['configuration_id'])->toBe($third->id)
        ->and($calculator->forSaturday('2026-11-21')['starting_pair'])->toBe(2)
        ->and($calculator->forSaturday('2026-12-05')['starting_pair'])->toBe(3)
        ->and($calculator->forSaturday('2026-12-19')['starting_pair'])->toBe(1)
        ->and($calculator->forSaturday('2027-01-30')['sunday'])->toBe('2027-01-31')
        ->and($calculator->forSaturday('2027-07-31')['sunday'])->toBe('2027-08-01');
});

it('uses membership effective on each requested weekend and preserves historical results', function () {
    pairAnchor();
    $admin = User::factory()->create();
    $ids = pairFixture('B', '2026-10-24');
    $configuration = app(WeekendPairConfigurationService::class)->create(pairInput('B', '2026-11-07', $ids), $admin->id);
    $calculator = app(WeekendPairRotationService::class);
    $historical = $calculator->forSaturday('2026-11-07');
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $ids[0], 'group_code' => 'A', 'effective_from_saturday' => '2026-11-21']);
    Doctor::query()->whereKey($ids[0])->update(['is_active' => false]);

    expect($calculator->forSaturday('2026-11-07'))->toBe($historical);
    try {
        $calculator->forSaturday('2026-11-21');
        $this->fail('A doctor in the wrong group was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['configuration'][0])->toContain((string) $ids[0], 'Group B', '2026-11-21');
    }
    expect($configuration->fresh()->doctors->pluck('doctor_id')->all())->toBe($ids)
        ->and(WeekendPairConfiguration::query()->count())->toBe(1);
});

it('rejects a missing effective membership when resolving a weekend', function () {
    pairAnchor();
    $admin = User::factory()->create();
    $ids = pairFixture('B', '2026-11-07');
    app(WeekendPairConfigurationService::class)->create(pairInput('B', '2026-11-07', $ids), $admin->id);
    DoctorWeekendGroupMembership::query()->where('doctor_id', $ids[0])->delete();

    expect(fn () => app(WeekendPairRotationService::class)->forSaturday('2026-11-07'))->toThrow(ValidationException::class);
});

it('rejects missing invalid and ambiguous group anchors in both services', function (string $case) {
    $admin = User::factory()->create();
    $ids = pairFixture('B', '2026-11-07');
    if ($case !== 'missing') {
        WeekendRotationConfiguration::query()->create([
            'anchor_saturday' => $case === 'weekday' ? '2026-11-06' : '2026-11-07',
            'anchor_group' => $case === 'group' ? 'C' : 'B',
        ]);
    }
    if ($case === 'ambiguous') {
        WeekendRotationConfiguration::query()->create(['singleton_key' => 'other', 'anchor_saturday' => '2026-11-07', 'anchor_group' => 'B']);
    }
    $input = pairInput('B', '2026-11-07', $ids);

    foreach ([
        fn () => app(WeekendPairConfigurationService::class)->create($input, $admin->id),
        fn () => app(WeekendPairRotationService::class)->forSaturday('2026-11-07'),
    ] as $operation) {
        try {
            $operation();
            $this->fail('An invalid group anchor was accepted.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['group_code'][0])->toBe('A single valid weekend group anchor is required.');
        }
    }
    expect(WeekendPairConfiguration::query()->count())->toBe(0);
})->with(['missing', 'weekday', 'group', 'ambiguous']);

it('returns a validation error for a non-scalar mode', function () {
    $input = ['mode' => [6], 'group_code' => 'B', 'effective_saturday' => '2026-11-07', 'doctor_ids' => [1, 2, 3, 4, 5, 6], 'pair_anchor_phase' => 0, 'extra_day_anchor_phase' => 0];
    try {
        app(WeekendPairConfigurationService::class)->create($input, 1);
        $this->fail('A malformed mode was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('mode');
    }
});

it('rejects invalid configurations and preserves the existing version', function () {
    pairAnchor();
    $admin = User::factory()->create();
    $ids = pairFixture('B', '2026-11-07', 7);
    $service = app(WeekendPairConfigurationService::class);
    $valid = pairInput('B', '2026-11-07', array_slice($ids, 0, 6));
    $service->create($valid, $admin->id);
    $invalid = [
        array_replace($valid, ['group_code' => 'C']),
        array_replace($valid, ['mode' => 8]),
        array_replace($valid, ['effective_saturday' => '2026-11-08']),
        array_replace($valid, ['effective_saturday' => '2026-11-14']),
        array_replace($valid, ['effective_saturday' => '2026-11-21', 'doctor_ids' => [$ids[0], $ids[0], $ids[2], $ids[3], $ids[4], $ids[5]]]),
        array_replace($valid, ['effective_saturday' => '2026-11-21', 'doctor_ids' => array_slice($ids, 0, 5)]),
        array_replace($valid, ['effective_saturday' => '2026-11-21', 'pair_anchor_phase' => 1]),
        array_replace($valid, ['effective_saturday' => '2026-11-21', 'extra_day_anchor_phase' => 7]),
        $valid,
    ];
    foreach ($invalid as $input) {
        try {
            $service->create($input, $admin->id);
            $this->fail('Invalid configuration was accepted.');
        } catch (ValidationException) {
            expect(WeekendPairConfiguration::query()->count())->toBe(1);
        }
    }
});

it('rejects a configuration affecting a final roster and mismatched membership', function () {
    pairAnchor();
    $admin = User::factory()->create();
    $ids = pairFixture('B', '2026-11-07');
    $other = pairFixture('A', '2026-11-07');
    $service = app(WeekendPairConfigurationService::class);
    $input = pairInput('B', '2026-11-07', $ids);
    $input['doctor_ids'][0] = $other[0];
    try {
        $service->create($input, $admin->id);
        $this->fail('Mismatched membership was accepted.');
    } catch (ValidationException) {
        expect(WeekendPairConfiguration::query()->count())->toBe(0);
    }

    $shiftType = ShiftType::query()->create(['code' => 'weekend_day', 'name' => 'Weekend Day', 'start_time' => '08:00', 'end_time' => '20:00', 'duration_minutes' => 720, 'main_count' => 2, 'optional_count' => 0, 'is_overnight' => false, 'is_active' => true]);
    $roster = Roster::query()->create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final]);
    $roster->shifts()->create(['shift_type_id' => $shiftType->id, 'shift_date' => '2026-11-08']);
    expect(fn () => $service->create(pairInput('B', '2026-11-07', $ids), $admin->id))->toThrow(ValidationException::class);
    expect(WeekendPairConfiguration::query()->count())->toBe(0);
});
