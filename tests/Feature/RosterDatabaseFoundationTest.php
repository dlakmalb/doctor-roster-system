<?php

use App\Enums\ActualWorkExceptionType;
use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Database\QueryException;

test('it seeds the fifteen expected doctors with unique short codes and UAT active status', function () {
    $this->seed(DoctorsSeeder::class);

    $this->assertDatabaseCount('doctors', 15);
    expect(Doctor::query()->distinct()->count('short_code'))->toBe(15)
        ->and(Doctor::query()->where('is_active', false)->count())->toBe(1)
        ->and(Doctor::query()->orderBy('short_code')->get(['short_code', 'name', 'is_active'])->map(fn (Doctor $doctor) => [$doctor->short_code, $doctor->name, $doctor->is_active])->all())->toBe([
            ['A', 'Dr Amarasinghe', true],
            ['B', 'Dr Buddhima', true],
            ['C', 'Dr Umanga', true],
            ['E', 'Dr Enasha', true],
            ['G', 'Dr Ganga', true],
            ['H', 'Dr Hirushini', false],
            ['I', 'Dr Amila', true],
            ['K', 'Dr Kasun', true],
            ['L', 'Dr Lasantha', true],
            ['M', 'Dr Mareena', true],
            ['N', 'Dr Nuwan (MOIC)', true],
            ['R', 'Dr Rajinda', true],
            ['S', 'Dr Sanath', true],
            ['T', 'Dr Thisara', true],
            ['U', 'Dr Nadun', true],
        ]);
});

test('it does not duplicate doctors when the doctor seeder runs twice', function () {
    $this->seed(DoctorsSeeder::class);
    $this->seed(DoctorsSeeder::class);

    $this->assertDatabaseCount('doctors', 15);
    expect(Doctor::query()->distinct()->count('short_code'))->toBe(15);
});

test('it preserves an inactive doctor when the doctor seeder runs again', function () {
    $this->seed(DoctorsSeeder::class);
    Doctor::query()->where('short_code', 'N')->update(['is_active' => false]);

    $this->seed(DoctorsSeeder::class);

    $this->assertDatabaseHas('doctors', [
        'short_code' => 'N',
        'name' => 'Dr Nuwan (MOIC)',
        'is_active' => false,
    ]);
});

test('it seeds the five expected shift definitions', function () {
    $this->seed(ShiftTypesSeeder::class);

    $this->assertDatabaseCount('shift_types', 5);
    expect(ShiftType::query()->orderBy('code')->get()->map(fn (ShiftType $shiftType) => [
        $shiftType->code,
        $shiftType->name,
        $shiftType->start_time,
        $shiftType->end_time,
        $shiftType->duration_minutes,
        $shiftType->main_count,
        $shiftType->optional_count,
        $shiftType->is_overnight,
        $shiftType->is_active,
    ])->all())->toBe([
        ['weekday_day', 'Weekday Day', '08:00:00', '14:00:00', 360, 4, 2, false, true],
        ['weekday_evening', 'Weekday Evening', '14:00:00', '20:00:00', 360, 3, 1, false, true],
        ['weekday_night', 'Weekday Night', '20:00:00', '08:00:00', 720, 2, 0, true, true],
        ['weekend_day', 'Weekend Day', '08:00:00', '16:00:00', 480, 3, 3, false, true],
        ['weekend_night', 'Weekend Night', '16:00:00', '08:00:00', 960, 2, 0, true, true],
    ]);
});

test('it does not duplicate shift types when the shift type seeder runs twice', function () {
    $this->seed(ShiftTypesSeeder::class);
    $this->seed(ShiftTypesSeeder::class);

    $this->assertDatabaseCount('shift_types', 5);
    expect(ShiftType::query()->distinct()->count('code'))->toBe(5);
});

test('it preserves existing shift type definitions and inactive state when reseeded', function () {
    $this->seed(ShiftTypesSeeder::class);
    ShiftType::query()->where('code', 'weekday_day')->update([
        'name' => 'Historical Weekday Day',
        'start_time' => '09:00:00',
        'end_time' => '15:00:00',
        'duration_minutes' => 360,
        'main_count' => 5,
        'optional_count' => 0,
        'is_overnight' => true,
        'is_active' => false,
    ]);

    $this->seed(ShiftTypesSeeder::class);

    $this->assertDatabaseHas('shift_types', [
        'code' => 'weekday_day',
        'name' => 'Historical Weekday Day',
        'start_time' => '09:00:00',
        'end_time' => '15:00:00',
        'duration_minutes' => 360,
        'main_count' => 5,
        'optional_count' => 0,
        'is_overnight' => true,
        'is_active' => false,
    ]);
});

test('it seeds domain records without creating a starter admin user', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('doctors', 15);
    $this->assertDatabaseCount('shift_types', 5);
});

test('it prevents a second roster for the same calendar month', function () {
    Roster::create(['year' => 2026, 'month' => 10]);

    expect(fn () => Roster::create(['year' => 2026, 'month' => 10]))
        ->toThrow(QueryException::class);
});

test('it prevents deleting a shift type referenced by a doctor request', function () {
    $doctor = Doctor::create(['name' => 'Dr Nuwan', 'short_code' => 'N']);
    $shiftType = ShiftType::create(['code' => 'day', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 360, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => false]);
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-01', 'shift_type_id' => $shiftType->id]);

    expect(fn () => $shiftType->delete())->toThrow(QueryException::class);
});

test('it prevents assigning the same doctor twice to one shift', function () {
    $doctor = Doctor::create(['name' => 'Dr Nuwan', 'short_code' => 'N']);
    $shiftType = ShiftType::create(['code' => 'day', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 360, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => false]);
    $roster = Roster::create(['year' => 2026, 'month' => 10]);
    $rosterShift = RosterShift::create(['roster_id' => $roster->id, 'shift_type_id' => $shiftType->id, 'shift_date' => '2026-10-01']);
    RosterAssignment::create(['roster_shift_id' => $rosterShift->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    expect(fn () => RosterAssignment::create(['roster_shift_id' => $rosterShift->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]))
        ->toThrow(QueryException::class);
});

test('it prevents two doctors occupying the same role and slot in one shift', function () {
    $firstDoctor = Doctor::create(['name' => 'Dr Nuwan', 'short_code' => 'N']);
    $secondDoctor = Doctor::create(['name' => 'Dr Sanath', 'short_code' => 'S']);
    $shiftType = ShiftType::create(['code' => 'day', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 360, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => false]);
    $roster = Roster::create(['year' => 2026, 'month' => 10]);
    $rosterShift = RosterShift::create(['roster_id' => $roster->id, 'shift_type_id' => $shiftType->id, 'shift_date' => '2026-10-01']);
    RosterAssignment::create(['roster_shift_id' => $rosterShift->id, 'doctor_id' => $firstDoctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    expect(fn () => RosterAssignment::create(['roster_shift_id' => $rosterShift->id, 'doctor_id' => $secondDoctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]))
        ->toThrow(QueryException::class);
});

test('it allows one workload per doctor and month and preserves signed balances', function () {
    $doctor = Doctor::create(['name' => 'Dr Nuwan', 'short_code' => 'N']);
    $january = DoctorMonthlyWorkload::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 1, 'source' => DoctorMonthlyWorkloadSource::ManualInitial, 'actual_worked_minutes' => 0, 'opening_balance_minutes' => -90, 'monthly_adjustment_minutes' => -15, 'closing_balance_minutes' => -105]);
    DoctorMonthlyWorkload::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 2, 'actual_worked_minutes' => 60]);

    $this->assertDatabaseCount('doctor_monthly_workloads', 2);
    expect($january->fresh()->opening_balance_minutes)->toBe(-90);
    expect(fn () => DoctorMonthlyWorkload::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 1, 'actual_worked_minutes' => 0]))
        ->toThrow(QueryException::class);
});

test('it casts enums and dates and navigates core roster relationships', function () {
    $user = User::factory()->create();
    $doctor = Doctor::create(['name' => 'Dr Nuwan', 'short_code' => 'N']);
    $shiftType = ShiftType::create(['code' => 'night', 'name' => 'Night', 'start_time' => '20:00', 'end_time' => '08:00', 'duration_minutes' => 720, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => true]);
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $user->id, 'updated_by' => $user->id, 'finalized_by' => $user->id, 'reopened_by' => $user->id, 'actual_work_confirmed_by' => $user->id]);
    $rosterShift = RosterShift::create(['roster_id' => $roster->id, 'shift_type_id' => $shiftType->id, 'shift_date' => '2026-10-01']);
    $assignment = RosterAssignment::create(['roster_shift_id' => $rosterShift->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $request = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-02', 'shift_type_id' => $shiftType->id, 'created_by' => $user->id, 'updated_by' => $user->id]);
    $exclusion = DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'created_by' => $user->id, 'updated_by' => $user->id]);
    $exception = ActualWorkException::create(['roster_shift_id' => $rosterShift->id, 'planned_assignment_id' => $assignment->id, 'exception_type' => ActualWorkExceptionType::MainAbsent, 'recorded_by' => $user->id]);
    $workload = DoctorMonthlyWorkload::create(['doctor_id' => $doctor->id, 'roster_id' => $roster->id, 'year' => 2026, 'month' => 10, 'actual_worked_minutes' => 720, 'most_recent_night_shift_at' => '2026-10-01 20:00:00']);

    expect($roster->fresh()->status)->toBe(RosterStatus::Final)
        ->and($rosterShift->fresh()->shift_date->toDateString())->toBe('2026-10-01')
        ->and($assignment->fresh()->role)->toBe(RosterAssignmentRole::Main)
        ->and($request->fresh()->request_type)->toBe(DoctorRequestType::DayOff)
        ->and($exception->fresh()->exception_type)->toBe(ActualWorkExceptionType::MainAbsent)
        ->and($workload->fresh()->source)->toBe(DoctorMonthlyWorkloadSource::System);

    expect($doctor->requests->modelKeys())->toBe([$request->id])
        ->and($doctor->monthlyExclusions->modelKeys())->toBe([$exclusion->id])
        ->and($doctor->rosterAssignments->modelKeys())->toBe([$assignment->id])
        ->and($doctor->monthlyWorkloads->modelKeys())->toBe([$workload->id])
        ->and($shiftType->rosterShifts->modelKeys())->toBe([$rosterShift->id])
        ->and($shiftType->doctorRequests->modelKeys())->toBe([$request->id])
        ->and($roster->shifts->modelKeys())->toBe([$rosterShift->id])
        ->and($roster->monthlyWorkloads->modelKeys())->toBe([$workload->id])
        ->and($rosterShift->assignments->modelKeys())->toBe([$assignment->id])
        ->and($rosterShift->actualWorkExceptions->modelKeys())->toBe([$exception->id])
        ->and($assignment->actualWorkExceptions->modelKeys())->toBe([$exception->id])
        ->and($exception->plannedAssignment->is($assignment))->toBeTrue()
        ->and($exception->recordedBy->is($user))->toBeTrue()
        ->and($roster->creator->is($user))->toBeTrue()
        ->and($roster->updater->is($user))->toBeTrue()
        ->and($roster->finalizer->is($user))->toBeTrue()
        ->and($roster->reopener->is($user))->toBeTrue()
        ->and($roster->actualWorkConfirmer->is($user))->toBeTrue()
        ->and($request->creator->is($user))->toBeTrue()
        ->and($request->updater->is($user))->toBeTrue()
        ->and($exclusion->creator->is($user))->toBeTrue()
        ->and($exclusion->updater->is($user))->toBeTrue()
        ->and($workload->doctor->is($doctor))->toBeTrue();
});
