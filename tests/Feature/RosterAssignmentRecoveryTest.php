<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RosterStructureService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;

/** @return array{User, list<Doctor>, list<RosterShift>} */
function recoveryScenario(array $shifts, int $doctorCount = 2): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $doctors = Doctor::query()->orderBy('id')->take($doctorCount)->get()->all();
    Doctor::query()->whereNotIn('id', array_map(fn (Doctor $doctor): int => $doctor->id, $doctors))->update(['is_active' => false]);

    $selected = [];
    foreach ($shifts as [$date, $code]) {
        $selected[] = RosterShift::query()->whereDate('shift_date', $date)
            ->whereHas('shiftType', fn ($query) => $query->where('code', $code))
            ->with('shiftType')->firstOrFail();
    }
    $roster->shifts()->whereNotIn('id', array_map(fn (RosterShift $shift): int => $shift->id, $selected))->delete();
    ShiftType::query()->whereIn('id', array_unique(array_map(fn (RosterShift $shift): int => $shift->shift_type_id, $selected)))
        ->update(['main_count' => 1, 'optional_count' => 0]);
    foreach ($selected as $shift) {
        $shift->load('shiftType');
    }

    return [$admin, $doctors, $selected];
}

function recoveryRequest(Doctor $doctor, RosterShift $shift, DoctorRequestType $type): void
{
    DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => $type,
        'request_date' => $shift->shift_date,
        'shift_type_id' => $shift->shift_type_id,
    ]);
}

function runRecovery(User $admin): void
{
    test()->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();
}

it('reassigns an earlier preferred doctor to fill a same-date Main slot', function () {
    [$admin, [$a, $b], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ]);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);

    runRecovery($admin);

    expect($day->assignments()->firstOrFail()->doctor_id)->toBe($b->id)
        ->and($evening->assignments()->firstOrFail()->doctor_id)->toBe($a->id);
    expect(app(DoctorAssignmentEligibilityService::class)->shiftConflict($day, $evening))->toBe('same_start_date');
});

it('reassigns an earlier preferred Night when recovery blocks the next day', function () {
    [$admin, [$a, $b], [$night, $day]] = recoveryScenario([
        ['2026-10-05', 'weekday_night'], ['2026-10-06', 'weekday_day'],
    ]);
    recoveryRequest($a, $night, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $day, DoctorRequestType::DayOff);

    runRecovery($admin);

    expect($night->assignments()->firstOrFail()->doctor_id)->toBe($b->id)
        ->and($day->assignments()->firstOrFail()->doctor_id)->toBe($a->id);
});

it('uses the best ranked feasible alternative during recovery', function () {
    [$admin, [$a, $b, $c], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ], 3);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);
    recoveryRequest($c, $evening, DoctorRequestType::DayOff);
    DoctorMonthlyWorkload::create([
        'doctor_id' => $c->id,
        'year' => 2026,
        'month' => 9,
        'actual_worked_minutes' => 0,
        'closing_balance_minutes' => 1000,
    ]);
    runRecovery($admin);

    expect($day->assignments()->firstOrFail()->doctor_id)->toBe($b->id)
        ->and($evening->assignments()->firstOrFail()->doctor_id)->toBe($a->id);
});

it('leaves an impossible slot empty and continues filling later slots', function () {
    [$admin, [$a], [$first, $second, $later]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'], ['2026-10-07', 'weekday_evening'],
    ], 1);
    runRecovery($admin);

    expect($first->assignments()->count())->toBe(1)
        ->and($second->assignments()->count())->toBe(0)
        ->and($later->assignments()->count())->toBe(1)
        ->and(RosterAssignment::query()->where('doctor_id', $a->id)->count())->toBe(2);
});

it('keeps prior Draft assignments fixed during normal fill', function () {
    [$admin, [$a, $b], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ]);
    $existing = $day->assignments()->create(['doctor_id' => $a->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);
    runRecovery($admin);

    expect($existing->fresh()->doctor_id)->toBe($a->id)
        ->and($evening->assignments()->count())->toBe(0);
});

it('does not displace Main staffing to fill Optional', function () {
    [$admin, [$a, $b], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ]);
    ShiftType::query()->whereKey($evening->shift_type_id)->update(['main_count' => 0, 'optional_count' => 1]);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);
    runRecovery($admin);

    expect($day->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id)->toBe($a->id)
        ->and($evening->assignments()->count())->toBe(0);
});
