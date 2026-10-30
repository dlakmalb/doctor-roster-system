<?php

use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Models\RosterShiftCapacitySnapshot;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\MonthlyShiftCapacityService;
use App\Services\RosterStructureService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function prepareMonthlyShiftCapacityTest(): void
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
}

it('uses global shift defaults when no monthly capacity is configured', function () {
    prepareMonthlyShiftCapacityTest();
    $shiftType = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();

    $capacity = app(MonthlyShiftCapacityService::class)->resolve(2026, 11, $shiftType);

    expect($capacity)->toBe(['main_count' => 4, 'optional_count' => 2]);
});

it('resolves custom main and optional capacities including zero optional positions', function () {
    prepareMonthlyShiftCapacityTest();
    $service = app(MonthlyShiftCapacityService::class);
    $service->create(2026, 11, 'weekday_day', 3, 2);
    $service->create(2026, 11, 'weekday_night', 2, 0);

    expect($service->resolve(2026, 11, 'weekday_day'))->toBe(['main_count' => 3, 'optional_count' => 2])
        ->and($service->resolve(2026, 11, 'weekday_night'))->toBe(['main_count' => 2, 'optional_count' => 0]);
});

it('isolates configurations by both year and month', function () {
    prepareMonthlyShiftCapacityTest();
    $service = app(MonthlyShiftCapacityService::class);
    $service->create(2026, 11, 'weekday_day', 3, 2);
    $service->create(2027, 11, 'weekday_day', 5, 4);

    expect($service->resolve(2026, 11, 'weekday_day'))->toBe(['main_count' => 3, 'optional_count' => 2])
        ->and($service->resolve(2026, 12, 'weekday_day'))->toBe(['main_count' => 4, 'optional_count' => 2])
        ->and($service->resolve(2027, 11, 'weekday_day'))->toBe(['main_count' => 5, 'optional_count' => 4]);
});

it('updates monthly settings before roster structure creation', function () {
    prepareMonthlyShiftCapacityTest();
    $service = app(MonthlyShiftCapacityService::class);
    $service->create(2026, 11, 'weekday_day', 3, 2);

    $updated = $service->update(2026, 11, 'weekday_day', 4, 3);

    expect($updated->main_count)->toBe(4)->and($updated->optional_count)->toBe(3);
    $this->assertDatabaseCount('monthly_shift_capacities', 1);
});

it('rejects invalid periods, unknown shifts, duplicate creates, and negative or oversized capacities without changing saved values', function () {
    prepareMonthlyShiftCapacityTest();
    $service = app(MonthlyShiftCapacityService::class);
    $existing = $service->create(2026, 11, 'weekday_day', 3, 2);

    expect(fn () => $service->create(2026, 13, 'weekday_day', 5, 5))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(0, 11, 'weekday_day', 5, 5))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(2026, 11, 'unknown', 5, 5))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(2026, 11, 'weekday_day', -1, 5))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(2026, 11, 'weekday_day', 5, 256))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(2026, 11, 'weekday_day', 5, 5))->toThrow(ValidationException::class);

    expect($existing->fresh()->main_count)->toBe(3)
        ->and($existing->fresh()->optional_count)->toBe(2);
    $this->assertDatabaseCount('monthly_shift_capacities', 1);
});

it('does not write any configuration when a multi-field request contains an invalid capacity', function () {
    prepareMonthlyShiftCapacityTest();
    $service = app(MonthlyShiftCapacityService::class);

    expect(fn () => $service->create(2026, 11, 'weekday_day', 3, -1))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('monthly_shift_capacities', 0);
});

it('rejects invalid monthly capacity inserts at the database boundary', function (array $invalidValues) {
    prepareMonthlyShiftCapacityTest();
    $shiftTypeId = ShiftType::query()->where('code', 'weekday_day')->value('id');
    $values = array_merge([
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $shiftTypeId,
        'main_count' => 3,
        'optional_count' => 2,
    ], $invalidValues);

    expect(fn () => DB::table('monthly_shift_capacities')->insert($values))
        ->toThrow(QueryException::class);
})->with([
    'month zero' => [['month' => 0]],
    'month thirteen' => [['month' => 13]],
    'year zero' => [['year' => 0]],
    'negative main capacity' => [['main_count' => -1]],
    'negative optional capacity' => [['optional_count' => -1]],
    'main capacity over 255' => [['main_count' => 256]],
    'optional capacity over 255' => [['optional_count' => 256]],
]);

it('rejects invalid monthly capacity updates at the database boundary without changing saved values', function (array $invalidValues) {
    prepareMonthlyShiftCapacityTest();
    $shiftTypeId = ShiftType::query()->where('code', 'weekday_day')->value('id');
    $id = DB::table('monthly_shift_capacities')->insertGetId([
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $shiftTypeId,
        'main_count' => 3,
        'optional_count' => 0,
    ]);

    expect(fn () => DB::table('monthly_shift_capacities')->where('id', $id)->update($invalidValues))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('monthly_shift_capacities', [
        'id' => $id,
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $shiftTypeId,
        'main_count' => 3,
        'optional_count' => 0,
    ]);
})->with([
    'month zero' => [['month' => 0]],
    'month thirteen' => [['month' => 13]],
    'year zero' => [['year' => 0]],
    'negative main capacity' => [['main_count' => -1]],
    'negative optional capacity' => [['optional_count' => -1]],
    'main capacity over 255' => [['main_count' => 256]],
    'optional capacity over 255' => [['optional_count' => 256]],
]);

it('rejects fractional monthly capacities directly in sqlite', function (array $invalidValues) {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite preserves fractional values for this database constraint check.');
    }

    prepareMonthlyShiftCapacityTest();
    $shiftTypeId = ShiftType::query()->where('code', 'weekday_day')->value('id');
    $values = array_merge([
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $shiftTypeId,
        'main_count' => 3,
        'optional_count' => 2,
    ], $invalidValues);

    expect(fn () => DB::table('monthly_shift_capacities')->insert($values))
        ->toThrow(QueryException::class);
})->with([
    'fractional main capacity' => [['main_count' => 1.5]],
    'fractional optional capacity' => [['optional_count' => 1.5]],
]);

it('rejects fractional monthly capacity updates directly in sqlite without changing saved values', function (array $invalidValues) {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite preserves fractional values for this database constraint check.');
    }

    prepareMonthlyShiftCapacityTest();
    $shiftTypeId = ShiftType::query()->where('code', 'weekday_day')->value('id');
    $id = DB::table('monthly_shift_capacities')->insertGetId([
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $shiftTypeId,
        'main_count' => 3,
        'optional_count' => 0,
    ]);

    expect(fn () => DB::table('monthly_shift_capacities')->where('id', $id)->update($invalidValues))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('monthly_shift_capacities', [
        'id' => $id,
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $shiftTypeId,
        'main_count' => 3,
        'optional_count' => 0,
    ]);
})->with([
    'fractional main capacity' => [['main_count' => 1.5]],
    'fractional optional capacity' => [['optional_count' => 1.5]],
]);

it('accepts valid database capacities including zero optional positions', function () {
    prepareMonthlyShiftCapacityTest();
    $shiftTypeId = ShiftType::query()->where('code', 'weekday_day')->value('id');
    $id = DB::table('monthly_shift_capacities')->insertGetId([
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $shiftTypeId,
        'main_count' => 1,
        'optional_count' => 0,
    ]);

    $this->assertDatabaseHas('monthly_shift_capacities', [
        'id' => $id,
        'year' => 2026,
        'month' => 11,
        'main_count' => 1,
        'optional_count' => 0,
    ]);
});

it('rejects capacity changes after a draft or final roster structure exists', function (RosterStatus $status) {
    prepareMonthlyShiftCapacityTest();
    Roster::create(['year' => 2026, 'month' => 11, 'status' => $status]);
    $service = app(MonthlyShiftCapacityService::class);

    expect(fn () => $service->create(2026, 11, 'weekday_day', 3, 2))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->update(2026, 11, 'weekday_day', 3, 2))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('monthly_shift_capacities', 0);
})->with([RosterStatus::Draft, RosterStatus::Final]);

it('preserves existing monthly settings and roster snapshots when changes are locked', function (RosterStatus $status) {
    prepareMonthlyShiftCapacityTest();
    $service = app(MonthlyShiftCapacityService::class);
    $configuration = $service->create(2026, 11, 'weekday_day', 3, 2);
    $roster = app(RosterStructureService::class)->create(2026, 11, User::factory()->create());
    if ($status === RosterStatus::Final) {
        $roster->update(['status' => RosterStatus::Final]);
    }
    $snapshotsBefore = RosterShiftCapacitySnapshot::query()
        ->orderBy('roster_shift_id')
        ->get(['roster_shift_id', 'main_count', 'optional_count'])
        ->toArray();

    expect(fn () => $service->update(2026, 11, 'weekday_day', 9, 8))
        ->toThrow(ValidationException::class);

    expect($configuration->fresh()->main_count)->toBe(3)
        ->and($configuration->fresh()->optional_count)->toBe(2)
        ->and(RosterShiftCapacitySnapshot::query()
            ->orderBy('roster_shift_id')
            ->get(['roster_shift_id', 'main_count', 'optional_count'])
            ->toArray())->toBe($snapshotsBefore);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 81);
})->with([RosterStatus::Draft, RosterStatus::Final]);

it('uses monthly settings for new roster snapshots while preserving existing october snapshots', function () {
    prepareMonthlyShiftCapacityTest();
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final]);
    $weekdayDay = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $octoberShift = $october->shifts()->create([
        'shift_type_id' => $weekdayDay->id,
        'shift_date' => '2026-10-01',
    ]);
    $octoberSnapshot = $octoberShift->capacitySnapshot()->create(['main_count' => 4, 'optional_count' => 2]);
    app(MonthlyShiftCapacityService::class)->create(2026, 11, 'weekday_day', 6, 5);

    $november = app(RosterStructureService::class)->create(2026, 11, User::factory()->create());
    $novemberSnapshot = $november->shifts()
        ->where('shift_type_id', $weekdayDay->id)
        ->firstOrFail()
        ->savedCapacity();

    expect($novemberSnapshot->main_count)->toBe(6)
        ->and($novemberSnapshot->optional_count)->toBe(5)
        ->and($octoberSnapshot->fresh()->main_count)->toBe(4)
        ->and($octoberSnapshot->fresh()->optional_count)->toBe(2);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 82);
});

it('supports increased capacities in future months without changing global defaults', function () {
    prepareMonthlyShiftCapacityTest();
    $globalShiftType = ShiftType::query()->where('code', 'weekend_day')->firstOrFail();
    app(MonthlyShiftCapacityService::class)->create(2027, 1, 'weekend_day', 8, 10);

    expect(app(MonthlyShiftCapacityService::class)->resolve(2027, 1, 'weekend_day'))
        ->toBe(['main_count' => 8, 'optional_count' => 10]);
    expect($globalShiftType->fresh()->main_count)->toBe(3)
        ->and($globalShiftType->fresh()->optional_count)->toBe(7);
});
