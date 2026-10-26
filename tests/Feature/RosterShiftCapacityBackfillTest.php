<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\RosterShiftCapacitySnapshot;
use App\Models\ShiftType;
use App\Services\RosterShiftCapacityBackfillService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;

function makeCapacityBackfillFixture(): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final]);
    $shiftType = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $shift = $roster->shifts()->create(['shift_type_id' => $shiftType->id, 'shift_date' => '2026-10-01']);
    $doctor = Doctor::query()->where('is_active', true)->firstOrFail();
    $assignment = RosterAssignment::create([
        'roster_shift_id' => $shift->id,
        'doctor_id' => $doctor->id,
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);
    $participation = DoctorMonthlyParticipation::create([
        'doctor_id' => $doctor->id,
        'roster_id' => $roster->id,
        'year' => 2026,
        'month' => 10,
        'is_participating' => true,
    ]);
    $workload = DoctorMonthlyWorkload::create([
        'doctor_id' => $doctor->id,
        'roster_id' => $roster->id,
        'year' => 2026,
        'month' => 10,
        'source' => DoctorMonthlyWorkloadSource::System,
        'actual_worked_minutes' => 360,
    ]);

    return [$roster, $shift, $assignment, $participation, $workload];
}

function verifiedCapacityManifest(RosterShift $shift, int $main = 4, int $optional = 2): array
{
    $shift->loadMissing(['roster', 'shiftType']);

    return ['capacities' => [[
        'shift_id' => $shift->id,
        'year' => $shift->roster->year,
        'month' => $shift->roster->month,
        'shift_date' => $shift->shift_date->toDateString(),
        'shift_type' => $shift->shiftType->code,
        'main_count' => $main,
        'optional_count' => $optional,
        'evidence' => 'Approved October 2026 roster source record, reviewed by the roster owner.',
    ]]];
}

it('reviews verified legacy capacities without changing existing roster business rows', function () {
    [$roster, $shift, $assignment, $participation, $workload] = makeCapacityBackfillFixture();
    $rosterBefore = $roster->fresh()->getAttributes();
    $shiftBefore = $shift->fresh()->getAttributes();
    $assignmentBefore = $assignment->fresh()->getAttributes();
    $participationBefore = $participation->fresh()->getAttributes();
    $workloadBefore = $workload->fresh()->getAttributes();

    $report = app(RosterShiftCapacityBackfillService::class)->review(verifiedCapacityManifest($shift));

    expect($report['ready'])->toBeTrue()
        ->and($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]['main_count'])->toBe(4)
        ->and($report['rows'][0]['optional_count'])->toBe(2);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
    expect($roster->fresh()->getAttributes())->toBe($rosterBefore)
        ->and($shift->fresh()->getAttributes())->toBe($shiftBefore)
        ->and($assignment->fresh()->getAttributes())->toBe($assignmentBefore)
        ->and($participation->fresh()->getAttributes())->toBe($participationBefore)
        ->and($workload->fresh()->getAttributes())->toBe($workloadBefore);
});

it('applies verified capacities without changing existing roster business rows', function () {
    [$roster, $shift, $assignment, $participation, $workload] = makeCapacityBackfillFixture();
    $rosterBefore = $roster->fresh()->getAttributes();
    $shiftBefore = $shift->fresh()->getAttributes();
    $assignmentBefore = $assignment->fresh()->getAttributes();
    $participationBefore = $participation->fresh()->getAttributes();
    $workloadBefore = $workload->fresh()->getAttributes();

    $report = app(RosterShiftCapacityBackfillService::class)->apply(verifiedCapacityManifest($shift, 1, 0));

    expect($report['ready'])->toBeTrue();
    $snapshot = RosterShiftCapacitySnapshot::query()->where('roster_shift_id', $shift->id)->sole();
    expect($snapshot->main_count)->toBe(1)->and($snapshot->optional_count)->toBe(0);
    expect($roster->fresh()->getAttributes())->toBe($rosterBefore)
        ->and($shift->fresh()->getAttributes())->toBe($shiftBefore)
        ->and($assignment->fresh()->getAttributes())->toBe($assignmentBefore)
        ->and($participation->fresh()->getAttributes())->toBe($participationBefore)
        ->and($workload->fresh()->getAttributes())->toBe($workloadBefore);
});

it('rejects an unverified or incomplete manifest without writing snapshots', function () {
    [, $shift] = makeCapacityBackfillFixture();
    $backfill = app(RosterShiftCapacityBackfillService::class);

    $missingEvidence = verifiedCapacityManifest($shift);
    $missingEvidence['capacities'][0]['evidence'] = '  ';
    $incomplete = $backfill->review($missingEvidence);
    $missingShift = $backfill->review(['capacities' => []]);

    expect($incomplete['ready'])->toBeFalse()
        ->and($incomplete['errors'])->toContain('Manifest row 1 must include a non-empty evidence note.')
        ->and($missingShift['ready'])->toBeFalse();
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
});

it('reports duplicate shift identities and makes no changes', function () {
    [, $shift] = makeCapacityBackfillFixture();
    $manifest = verifiedCapacityManifest($shift);
    $manifest['capacities'][] = $manifest['capacities'][0];

    $report = app(RosterShiftCapacityBackfillService::class)->apply($manifest);

    expect($report['ready'])->toBeFalse()
        ->and($report['errors'])->toContain("Shift {$shift->id} appears more than once in the manifest.");
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
});

it('rejects capacities that conflict with existing assignments', function () {
    [, $shift] = makeCapacityBackfillFixture();

    $report = app(RosterShiftCapacityBackfillService::class)->apply(verifiedCapacityManifest($shift, 0, 2));

    expect($report['ready'])->toBeFalse()
        ->and($report['errors'])->toContain("Roster shift {$shift->id} has an assignment in main slot 1, outside the proposed capacity of 0.");
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
});

it('never overwrites a conflicting existing snapshot', function () {
    [, $shift] = makeCapacityBackfillFixture();
    $snapshot = $shift->capacitySnapshot()->create(['main_count' => 4, 'optional_count' => 2]);

    $report = app(RosterShiftCapacityBackfillService::class)->apply(verifiedCapacityManifest($shift, 4, 3));

    expect($report['ready'])->toBeFalse()
        ->and($report['errors'])->toContain("Roster shift {$shift->id} already has a conflicting capacity snapshot; it will not be overwritten.");
    expect($snapshot->fresh()->optional_count)->toBe(2);
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 1);
});

it('rejects changes to persisted snapshot values', function () {
    [, $shift] = makeCapacityBackfillFixture();
    $snapshot = $shift->capacitySnapshot()->create(['main_count' => 4, 'optional_count' => 2]);

    expect(fn () => $snapshot->update(['optional_count' => 3]))
        ->toThrow(LogicException::class, 'Roster shift capacity snapshots are immutable.');
});

it('rejects negative capacity values before inserting a snapshot', function () {
    [, $shift] = makeCapacityBackfillFixture();

    expect(fn () => $shift->capacitySnapshot()->create(['main_count' => -1, 'optional_count' => 2]))
        ->toThrow(InvalidArgumentException::class, 'The main_count capacity must be an integer from 0 through 255.');
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
});

it('rejects a manifest with an incorrect shift identity without inserting snapshots', function (string $field, string|int $value) {
    [, $shift] = makeCapacityBackfillFixture();
    $manifest = verifiedCapacityManifest($shift);
    $manifest['capacities'][0][$field] = $value;

    $report = app(RosterShiftCapacityBackfillService::class)->apply($manifest);

    expect($report['ready'])->toBeFalse()
        ->and($report['errors'][0])->toContain('identity mismatch');
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 0);
})->with([
    ['year', 2025],
    ['month', 9],
    ['shift_date', '2026-10-02'],
    ['shift_type', 'weekend_day'],
]);

it('rejects deletion of an individual snapshot while preserving shift cascade deletion', function () {
    [, $shift] = makeCapacityBackfillFixture();
    $snapshot = $shift->capacitySnapshot()->create(['main_count' => 4, 'optional_count' => 2]);

    expect(fn () => $snapshot->delete())
        ->toThrow(LogicException::class, 'Roster shift capacity snapshots cannot be deleted while their roster shift exists.');
    expect($snapshot->fresh())->not->toBeNull();

    $shift->delete();

    $this->assertDatabaseMissing('roster_shifts', ['id' => $shift->id]);
    $this->assertDatabaseMissing('roster_shift_capacity_snapshots', ['id' => $snapshot->id]);
});

it('keeps applying an already saved matching manifest idempotent', function () {
    [, $shift] = makeCapacityBackfillFixture();
    $backfill = app(RosterShiftCapacityBackfillService::class);
    $manifest = verifiedCapacityManifest($shift);

    expect($backfill->apply($manifest)['ready'])->toBeTrue()
        ->and($backfill->apply($manifest)['ready'])->toBeTrue();
    $this->assertDatabaseCount('roster_shift_capacity_snapshots', 1);
});
