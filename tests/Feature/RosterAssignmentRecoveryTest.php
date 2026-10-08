<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RosterAssignmentRecoveryService;
use App\Services\RosterCandidateRanker;
use App\Services\RosterDraftValidationService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Facades\DB;

/** @return array{User, list<Doctor>, list<RosterShift>} */
function recoveryScenario(array $shifts, int $doctorCount = 2): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $admin = User::factory()->create();
    $doctors = Doctor::query()->orderBy('id')->take($doctorCount)->get()->all();
    Doctor::query()->whereNotIn('id', array_map(fn (Doctor $doctor): int => $doctor->id, $doctors))->update(['is_active' => false]);
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);

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

it('performs recursive recovery without database queries after inputs are loaded', function () {
    [, [$a, $b], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ]);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);
    $shifts = RosterShift::query()->with(['shiftType', 'assignments'])->whereIn('id', [$day->id, $evening->id])->get();
    $requests = DoctorRequest::query()->with('shiftType')->get();
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize($shifts, $requests->where('request_type', DoctorRequestType::PreferredWork), collect(), CarbonImmutable::parse('2026-10-01'));
    $recovery = app(RosterAssignmentRecoveryService::class);
    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $assignments = $recovery->plan($shifts, collect([$a, $b]), collect(), $requests->where('request_type', DoctorRequestType::DayOff)->groupBy('doctor_id'), collect(), $ranker);

    expect($queryCount)->toBe(0);
    expect($recovery->diagnostics()['totals']['chains'])->toBeGreaterThan(0);
    expect(collect($assignments)->where('roster_shift_id', $day->id)->first()['doctor_id'])->toBe($b->id);
    expect(collect($assignments)->where('roster_shift_id', $evening->id)->first()['doctor_id'])->toBe($a->id);
});

it('prefers a one-blocker repair over a better ranked two-blocker repair', function () {
    [, [$a, $b, $c], [$earlierNight, $targetNight, $laterDay, $laterEvening]] = recoveryScenario([
        ['2026-10-06', 'weekday_night'], ['2026-10-07', 'weekday_night'],
        ['2026-10-08', 'weekday_day'], ['2026-10-08', 'weekday_evening'],
    ], 3);
    recoveryRequest($b, $targetNight, DoctorRequestType::PreferredWork);
    recoveryRequest($c, $targetNight, DoctorRequestType::DayOff);
    $requests = DoctorRequest::query()->with('shiftType')->get();
    $shifts = RosterShift::query()->with(['shiftType', 'assignments'])->get();
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize($shifts, $requests->where('request_type', DoctorRequestType::PreferredWork), collect(), CarbonImmutable::parse('2026-10-01'));
    $recovery = app(RosterAssignmentRecoveryService::class);
    $recovery->plan($shifts, collect([$a, $b, $c]), collect(), $requests->where('request_type', DoctorRequestType::DayOff)->groupBy('doctor_id'), collect(), $ranker);
    $plan = new ReflectionProperty($recovery, 'plan');
    $plan->setValue($recovery, [
        "$earlierNight->id:main:1" => ['shift' => $earlierNight, 'role' => RosterAssignmentRole::Main, 'slot' => 1, 'doctor_id' => $b->id, 'fixed' => false],
        "$laterDay->id:main:1" => ['shift' => $laterDay, 'role' => RosterAssignmentRole::Main, 'slot' => 1, 'doctor_id' => $b->id, 'fixed' => false],
        "$laterEvening->id:main:1" => ['shift' => $laterEvening, 'role' => RosterAssignmentRole::Main, 'slot' => 1, 'doctor_id' => $a->id, 'fixed' => false],
    ]);
    $explored = 0;
    $visited = [];
    $arguments = [$targetNight, RosterAssignmentRole::Main, 1, 0, &$explored, &$visited, true];

    $repaired = (new ReflectionMethod($recovery, 'place'))->invokeArgs($recovery, $arguments);
    $result = $plan->getValue($recovery);

    expect($repaired)->toBeTrue();
    expect($result["$targetNight->id:main:1"]['doctor_id'])->toBe($a->id);
    expect($result["$laterEvening->id:main:1"]['doctor_id'])->toBe($c->id);
    expect($result["$earlierNight->id:main:1"]['doctor_id'])->toBe($b->id);
    expect($result["$laterDay->id:main:1"]['doctor_id'])->toBe($b->id);
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
    DoctorMonthlyWorkload::query()->updateOrCreate(['doctor_id' => $c->id, 'year' => 2026, 'month' => 9], [
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

it('moves generated Main staffing to fill a required Optional slot', function () {
    [$admin, [$a, $b], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ]);
    ShiftType::query()->whereKey($evening->shift_type_id)->update(['main_count' => 0, 'optional_count' => 1]);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);
    runRecovery($admin);

    expect($day->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id)->toBe($b->id)
        ->and($evening->assignments()->where('role', RosterAssignmentRole::Optional)->firstOrFail()->doctor_id)->toBe($a->id);
    expect(app(DoctorAssignmentEligibilityService::class)->shiftConflict($day, $evening))->toBe('same_start_date');
    expect(collect(app(RosterDraftValidationService::class)->validate(Roster::query()->firstOrFail()))->where('severity', 'Error')->count())->toBe(0);
});

it('repairs a cross-role chain through another generated Main position', function () {
    [$admin, [$a, $b, $c], [$day, $evening, $night]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'], ['2026-10-06', 'weekday_night'],
    ], 3);
    ShiftType::query()->whereKey($night->shift_type_id)->update(['main_count' => 0, 'optional_count' => 1]);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $evening, DoctorRequestType::PreferredWork);
    recoveryRequest($c, $day, DoctorRequestType::DayOff);
    recoveryRequest($c, $night, DoctorRequestType::DayOff);
    DoctorMonthlyWorkload::query()->where('doctor_id', $b->id)->where('month', 9)->update(['optional_assignment_count' => 1]);
    DoctorMonthlyWorkload::query()->where('doctor_id', $c->id)->where('month', 9)->update(['optional_assignment_count' => 2]);

    runRecovery($admin);

    expect([
        $day->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id,
        $evening->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id,
        $night->assignments()->where('role', RosterAssignmentRole::Optional)->firstOrFail()->doctor_id,
    ])->toBe([$b->id, $c->id, $a->id]);
    expect(collect(app(RosterDraftValidationService::class)->validate(Roster::query()->firstOrFail()))->where('severity', 'Error')->count())->toBe(0);
});

it('fills Main directly before moving a generated Optional doctor', function () {
    [, [$a, $b], [$day]] = recoveryScenario([['2026-10-06', 'weekday_day']]);
    ShiftType::query()->whereKey($day->shift_type_id)->update(['main_count' => 1, 'optional_count' => 1]);
    $day->load('shiftType');
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);

    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize(collect([$day]), DoctorRequest::query()->get(), collect(), CarbonImmutable::parse('2026-10-01'));
    $recovery = app(RosterAssignmentRecoveryService::class);
    $recovery->plan(collect([$day]), collect([$a, $b]), collect(), collect(), collect(), $ranker);
    $plan = new ReflectionProperty($recovery, 'plan');
    $plan->setValue($recovery, [
        "$day->id:optional:1" => ['shift' => $day, 'role' => RosterAssignmentRole::Optional, 'slot' => 1, 'doctor_id' => $a->id, 'fixed' => false],
    ]);
    $explored = 0;
    $visited = [];
    $arguments = [$day, RosterAssignmentRole::Main, 1, 0, &$explored, &$visited, true];

    $repaired = (new ReflectionMethod($recovery, 'place'))->invokeArgs($recovery, $arguments);
    $result = $plan->getValue($recovery);

    expect($repaired)->toBeTrue();
    expect($result["$day->id:main:1"]['doctor_id'])->toBe($b->id)
        ->and($result["$day->id:optional:1"]['doctor_id'])->toBe($a->id)
        ->and($explored)->toBe(1);
    expect($ranker->dimensions($a, $day, RosterAssignmentRole::Optional, collect()))->toBe([1, 1, 0]);
});

it('fills Optional directly before moving a generated Main doctor on the same shift', function () {
    [, [$a, $b], [$day]] = recoveryScenario([['2026-10-06', 'weekday_day']]);
    ShiftType::query()->whereKey($day->shift_type_id)->update(['main_count' => 1, 'optional_count' => 1]);
    $day->load('shiftType');
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    DoctorMonthlyWorkload::query()->where('doctor_id', $b->id)->where('month', 9)->update(['optional_assignment_count' => 1]);
    $history = DoctorMonthlyWorkload::query()->where('month', 9)->get()->keyBy('doctor_id');

    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize(collect([$day]), DoctorRequest::query()->get(), $history, CarbonImmutable::parse('2026-10-01'));
    $recovery = app(RosterAssignmentRecoveryService::class);
    $recovery->plan(collect([$day]), collect([$a, $b]), collect(), collect(), $history, $ranker);
    $plan = new ReflectionProperty($recovery, 'plan');
    $plan->setValue($recovery, [
        "$day->id:main:1" => ['shift' => $day, 'role' => RosterAssignmentRole::Main, 'slot' => 1, 'doctor_id' => $a->id, 'fixed' => false],
    ]);
    $explored = 0;
    $visited = [];
    $arguments = [$day, RosterAssignmentRole::Optional, 1, 0, &$explored, &$visited, true];

    $repaired = (new ReflectionMethod($recovery, 'place'))->invokeArgs($recovery, $arguments);
    $result = $plan->getValue($recovery);

    expect($repaired)->toBeTrue();
    expect($result["$day->id:main:1"]['doctor_id'])->toBe($a->id)
        ->and($result["$day->id:optional:1"]['doctor_id'])->toBe($b->id)
        ->and($explored)->toBe(1);
    expect(collect($result)->pluck('doctor_id')->unique()->count())->toBe(2);
});

it('keeps fixed Main staffing in place when Optional requires that doctor', function () {
    [$admin, [$a, $b], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ]);
    ShiftType::query()->whereKey($evening->shift_type_id)->update(['main_count' => 0, 'optional_count' => 1]);
    $existing = $day->assignments()->create(['doctor_id' => $a->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);

    runRecovery($admin);

    expect($existing->fresh()->doctor_id)->toBe($a->id)
        ->and($evening->assignments()->count())->toBe(0);

    $shifts = RosterShift::query()->with(['shiftType', 'assignments'])->whereIn('id', [$day->id, $evening->id])->get();
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize($shifts, collect(), collect(), CarbonImmutable::parse('2026-10-01'));
    $recovery = app(RosterAssignmentRecoveryService::class);
    $recovery->plan($shifts, collect([$a, $b]), collect(), DoctorRequest::query()->with('shiftType')->get()->groupBy('doctor_id'), collect(), $ranker);

    expect($recovery->unfilledDiagnostics()["$evening->id:optional:1"])->toHaveKey('fixed_blockers');
});

it('restores a failed cross-role branch before trying another Optional candidate', function () {
    [$admin, [$a, $b, $c], [$day, $evening, $night]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'], ['2026-10-06', 'weekday_night'],
    ], 3);
    ShiftType::query()->whereKey($night->shift_type_id)->update(['main_count' => 0, 'optional_count' => 1]);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $evening, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $day, DoctorRequestType::DayOff);
    recoveryRequest($c, $day, DoctorRequestType::DayOff);
    recoveryRequest($c, $night, DoctorRequestType::DayOff);
    DoctorMonthlyWorkload::query()->where('doctor_id', $b->id)->where('month', 9)->update(['optional_assignment_count' => 1]);

    runRecovery($admin);

    expect($day->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id)->toBe($a->id)
        ->and($evening->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id)->toBe($c->id)
        ->and($night->assignments()->where('role', RosterAssignmentRole::Optional)->firstOrFail()->doctor_id)->toBe($b->id)
        ->and(RosterAssignment::query()->count())->toBe(3);
});

it('leaves Optional empty when every cross-role repair violates Day-Off', function () {
    [$admin, [$a, $b], [$day, $evening]] = recoveryScenario([
        ['2026-10-06', 'weekday_day'], ['2026-10-06', 'weekday_evening'],
    ]);
    ShiftType::query()->whereKey($evening->shift_type_id)->update(['main_count' => 0, 'optional_count' => 1]);
    recoveryRequest($a, $day, DoctorRequestType::PreferredWork);
    recoveryRequest($b, $day, DoctorRequestType::DayOff);
    recoveryRequest($b, $evening, DoctorRequestType::DayOff);

    runRecovery($admin);

    expect($day->assignments()->firstOrFail()->doctor_id)->toBe($a->id)
        ->and($evening->assignments()->count())->toBe(0);
});
