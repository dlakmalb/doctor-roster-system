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
use App\Services\RosterCandidateRanker;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;

function smartRoster(): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $doctors = Doctor::query()->orderBy('id')->take(2)->get();

    return [$admin, $roster, $doctors[0], $doctors[1]];
}

function smartShift(string $date, string $code): RosterShift
{
    return RosterShift::query()->whereDate('shift_date', $date)
        ->whereHas('shiftType', fn ($query) => $query->where('code', $code))
        ->with(['shiftType', 'assignments'])->firstOrFail();
}

function smartRanker(array $requests = [], array $history = []): RosterCandidateRanker
{
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize(
        RosterShift::query()->with(['shiftType', 'assignments'])->get(),
        collect($requests),
        collect($history)->keyBy('doctor_id'),
        CarbonImmutable::parse('2026-10-01'),
    );

    return $ranker;
}

function smartPreference(Doctor $doctor, RosterShift $shift): DoctorRequest
{
    return DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => $shift->shift_date,
        'shift_type_id' => $shift->shift_type_id,
    ]);
}

function smartHistory(Doctor $doctor, array $attributes): DoctorMonthlyWorkload
{
    return DoctorMonthlyWorkload::create(array_merge([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 9,
        'actual_worked_minutes' => 0,
    ], $attributes));
}

it('orders deterministic dimensions and retains every exact tie without doctor ID priority', function () {
    [, , $first, $second] = smartRoster();
    $shift = smartShift('2026-10-06', 'weekday_day');
    $ranker = smartRanker([smartPreference($second, $shift)]);

    expect($ranker->ordered(collect([$first, $second]), $shift, RosterAssignmentRole::Main, collect())->pluck('id')->all())
        ->toBe([$second->id, $first->id]);

    $tiedRanker = smartRanker();
    $ordered = $tiedRanker->ordered(collect([$first, $second]), $shift, RosterAssignmentRole::Main, collect());
    expect($ordered->pluck('id')->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and($tiedRanker->dimensions($first, $shift, RosterAssignmentRole::Main, collect()))
        ->toBe($tiedRanker->dimensions($second, $shift, RosterAssignmentRole::Main, collect()));
});

it('puts exact Main preference before workload and Night fairness but gives Optional no direct boost', function () {
    [, , $preferred, $other] = smartRoster();
    $night = smartShift('2026-10-05', 'weekday_night');
    $request = smartPreference($preferred, $night);
    $history = [smartHistory($preferred, ['closing_balance_minutes' => 1500, 'actual_night_duty_count' => 5])];
    $ranker = smartRanker([$request], $history);

    expect($ranker->dimensions($preferred, $night, RosterAssignmentRole::Main, collect()) < $ranker->dimensions($other, $night, RosterAssignmentRole::Main, collect()))->toBeTrue();
    expect($ranker->dimensions($preferred, $night, RosterAssignmentRole::Optional, collect()))->toBe([1, 0, 1500]);
    expect($ranker->dimensions($other, $night, RosterAssignmentRole::Optional, collect()) < $ranker->dimensions($preferred, $night, RosterAssignmentRole::Optional, collect()))->toBeTrue();
});

it('protects future preference from Night recovery and keeps that protection soft', function () {
    [, $roster, $preferred, $other] = smartRoster();
    $mondayNight = smartShift('2026-10-05', 'weekday_night');
    $tuesdayDay = smartShift('2026-10-06', 'weekday_day');
    $ranker = smartRanker([smartPreference($preferred, $tuesdayDay)]);

    expect($ranker->dimensions($other, $mondayNight, RosterAssignmentRole::Main, collect()) < $ranker->dimensions($preferred, $mondayNight, RosterAssignmentRole::Main, collect()))->toBeTrue();
    expect($ranker->select(collect([$preferred]), $mondayNight, RosterAssignmentRole::Main, collect()))->toBe($preferred);
});

it('derives preference fulfillment from Main only and protects same-shift Optional', function () {
    [, , $preferred, $other] = smartRoster();
    $shift = smartShift('2026-10-06', 'weekday_day');
    $ranker = smartRanker([smartPreference($preferred, $shift)]);

    expect($ranker->dimensions($preferred, $shift, RosterAssignmentRole::Optional, collect())[0])->toBe(1);
    $ranker->record($preferred->id, $shift, RosterAssignmentRole::Optional);
    expect($ranker->dimensions($preferred, $shift, RosterAssignmentRole::Optional, collect())[0])->toBe(1);
    $ranker->record($preferred->id, $shift, RosterAssignmentRole::Main);
    expect($ranker->dimensions($preferred, $shift, RosterAssignmentRole::Optional, collect())[0])->toBe(0);
});

it('groups Friday Night with its Saturday and uses historical and current weekend rotation', function () {
    [, , $worked, $other] = smartRoster();
    $friday = smartShift('2026-10-02', 'weekday_night');
    $saturday = smartShift('2026-10-03', 'weekend_day');
    $nextSaturday = smartShift('2026-10-10', 'weekend_day');
    $ranker = smartRanker([], [smartHistory($worked, ['worked_final_weekend' => true, 'closing_balance_minutes' => -1000])]);

    expect($ranker->weekendKey($friday))->toBe('2026-10-03');
    expect($ranker->dimensions($other, $friday, RosterAssignmentRole::Main, collect()) < $ranker->dimensions($worked, $friday, RosterAssignmentRole::Main, collect()))->toBeTrue();
    $ranker->record($worked->id, $friday, RosterAssignmentRole::Main);
    expect($ranker->dimensions($worked, $saturday, RosterAssignmentRole::Main, collect())[3])->toBe(1);
    expect($ranker->dimensions($worked, $nextSaturday, RosterAssignmentRole::Main, collect())[2])->toBe(1);
});

it('puts exact preference before previous-weekend history and ignores Optional weekend work', function () {
    [, , $worked, $other] = smartRoster();
    $firstWeekend = smartShift('2026-10-03', 'weekend_day');
    $nextWeekend = smartShift('2026-10-10', 'weekend_day');
    $ranker = smartRanker([smartPreference($worked, $firstWeekend)], [smartHistory($worked, ['worked_final_weekend' => true])]);

    expect($ranker->dimensions($worked, $firstWeekend, RosterAssignmentRole::Main, collect()) < $ranker->dimensions($other, $firstWeekend, RosterAssignmentRole::Main, collect()))->toBeTrue();
    $ranker->record($worked->id, $firstWeekend, RosterAssignmentRole::Optional);
    expect($ranker->dimensions($worked, $nextWeekend, RosterAssignmentRole::Main, collect())[2])->toBe(0);
});

it('uses the previous months final weekend when the new month starts on Sunday', function () {
    [$admin, , $worked] = smartRoster();
    $november = app(RosterStructureService::class)->create(2026, 11, $admin);
    $sunday = $november->shifts()->whereDate('shift_date', '2026-11-01')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))
        ->with(['shiftType', 'assignments'])->firstOrFail();
    $nextSunday = $november->shifts()->whereDate('shift_date', '2026-11-08')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))
        ->with(['shiftType', 'assignments'])->firstOrFail();
    $history = DoctorMonthlyWorkload::create(['doctor_id' => $worked->id, 'year' => 2026, 'month' => 10, 'actual_worked_minutes' => 0, 'worked_final_weekend' => true]);
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize($november->shifts()->with(['shiftType', 'assignments'])->get(), collect(), collect([$history])->keyBy('doctor_id'), CarbonImmutable::parse('2026-11-01'));

    expect($ranker->dimensions($worked, $sunday, RosterAssignmentRole::Main, collect())[2])->toBe(0);
    expect($ranker->dimensions($worked, $sunday, RosterAssignmentRole::Main, collect())[3])->toBe(1);
    expect($ranker->dimensions($worked, $nextSunday, RosterAssignmentRole::Main, collect())[2])->toBe(1);
});

it('counts current Main work on an overlapping Sunday as previous-weekend work', function () {
    [$admin, , $doctor, $other] = smartRoster();
    $november = app(RosterStructureService::class)->create(2026, 11, $admin);
    $sunday = $november->shifts()->whereDate('shift_date', '2026-11-01')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))
        ->with(['shiftType', 'assignments'])->firstOrFail();
    $nextWeekend = $november->shifts()->whereDate('shift_date', '2026-11-07')
        ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))
        ->with(['shiftType', 'assignments'])->firstOrFail();
    $history = DoctorMonthlyWorkload::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'actual_worked_minutes' => 0, 'worked_final_weekend' => false]);
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize($november->shifts()->with(['shiftType', 'assignments'])->get(), collect(), collect([$history])->keyBy('doctor_id'), CarbonImmutable::parse('2026-11-01'));

    expect($ranker->dimensions($doctor, $nextWeekend, RosterAssignmentRole::Main, collect())[2])->toBe(0);
    $ranker->record($doctor->id, $sunday, RosterAssignmentRole::Optional);
    expect($ranker->dimensions($doctor, $nextWeekend, RosterAssignmentRole::Main, collect())[2])->toBe(0);
    $ranker->record($doctor->id, $sunday, RosterAssignmentRole::Main);
    expect($ranker->dimensions($doctor, $nextWeekend, RosterAssignmentRole::Main, collect())[2])->toBe(1);
    expect($ranker->dimensions($other, $nextWeekend, RosterAssignmentRole::Main, collect())[2])->toBe(0);
});

it('counts previous and current Night and Optional duties before workload', function () {
    [, , $doctor, $other] = smartRoster();
    $weekdayNight = smartShift('2026-10-05', 'weekday_night');
    $weekendNight = smartShift('2026-10-10', 'weekend_night');
    $day = smartShift('2026-10-06', 'weekday_day');
    $ranker = smartRanker([], [smartHistory($doctor, ['actual_night_duty_count' => 2, 'optional_assignment_count' => 3, 'closing_balance_minutes' => -1000])]);

    expect($ranker->dimensions($other, $weekdayNight, RosterAssignmentRole::Main, collect()) < $ranker->dimensions($doctor, $weekdayNight, RosterAssignmentRole::Main, collect()))->toBeTrue();
    $ranker->record($doctor->id, $weekdayNight, RosterAssignmentRole::Main);
    $ranker->record($doctor->id, $weekendNight, RosterAssignmentRole::Main);
    expect($ranker->dimensions($doctor, $weekdayNight, RosterAssignmentRole::Main, collect())[2])->toBe(4);
    expect($ranker->dimensions($doctor, $day, RosterAssignmentRole::Optional, collect()))->toBe([0, 3, 680]);
    $ranker->record($doctor->id, $day, RosterAssignmentRole::Optional);
    expect($ranker->dimensions($doctor, $day, RosterAssignmentRole::Optional, collect()))->toBe([0, 4, 680]);
});

it('resolves multiple preferred candidates with Night count and Optional candidates with their count', function () {
    [, , $doctor, $other] = smartRoster();
    $night = smartShift('2026-10-05', 'weekday_night');
    $day = smartShift('2026-10-06', 'weekday_day');
    $ranker = smartRanker(
        [smartPreference($doctor, $night), smartPreference($other, $night)],
        [
            smartHistory($doctor, ['actual_night_duty_count' => 1, 'optional_assignment_count' => 0, 'closing_balance_minutes' => 2000]),
            smartHistory($other, ['actual_night_duty_count' => 2, 'optional_assignment_count' => 3, 'closing_balance_minutes' => -2000]),
        ],
    );

    expect($ranker->dimensions($doctor, $night, RosterAssignmentRole::Main, collect()) < $ranker->dimensions($other, $night, RosterAssignmentRole::Main, collect()))->toBeTrue();
    expect($ranker->dimensions($doctor, $day, RosterAssignmentRole::Optional, collect()) < $ranker->dimensions($other, $day, RosterAssignmentRole::Optional, collect()))->toBeTrue();
});

it('does not protect a preference for an already passed shift', function () {
    [, , $doctor] = smartRoster();
    $past = smartShift('2026-10-05', 'weekday_day');
    $current = smartShift('2026-10-05', 'weekday_evening');
    $next = smartShift('2026-10-06', 'weekday_day');
    $ranker = smartRanker([smartPreference($doctor, $past)]);

    expect($ranker->dimensions($doctor, $next, RosterAssignmentRole::Main, collect())[1])->toBe(0);
    expect($ranker->dimensions($doctor, $current, RosterAssignmentRole::Main, collect())[1])->toBe(0);
});

it('balances ordinary duties with signed carry and current Main minutes', function () {
    [, , $doctor, $other] = smartRoster();
    $weekendDay = smartShift('2026-10-03', 'weekend_day');
    $evening = smartShift('2026-10-06', 'weekday_evening');
    $ranker = smartRanker([], [smartHistory($doctor, ['closing_balance_minutes' => -600])]);

    expect($ranker->dimensions($doctor, $evening, RosterAssignmentRole::Main, collect()))->toBe([1, 0, -600]);
    $ranker->record($doctor->id, $weekendDay, RosterAssignmentRole::Main);
    expect($ranker->dimensions($doctor, $evening, RosterAssignmentRole::Main, collect()))->toBe([1, 0, -120]);
    $ranker->record($doctor->id, $evening, RosterAssignmentRole::Main);
    expect($ranker->dimensions($doctor, $evening, RosterAssignmentRole::Main, collect()))->toBe([1, 0, 240]);
    expect($ranker->dimensions($other, $evening, RosterAssignmentRole::Main, collect()) < $ranker->dimensions($doctor, $evening, RosterAssignmentRole::Main, collect()))->toBeTrue();
});

it('treats equal deterministic dimensions as a tie independent of doctor ID', function () {
    [, , $first, $second] = smartRoster();
    $shift = smartShift('2026-10-06', 'weekday_evening');
    $ranker = smartRanker();

    expect($ranker->dimensions($first, $shift, RosterAssignmentRole::Main, collect()))
        ->toBe($ranker->dimensions($second, $shift, RosterAssignmentRole::Main, collect()));
});

it('keeps hard-ineligible preferred doctors out of generation', function () {
    [$admin, $roster, $preferred] = smartRoster();
    $shift = smartShift('2026-10-06', 'weekday_day');
    smartPreference($preferred, $shift);
    $preferred->update(['is_active' => false]);
    $roster->shifts()->where('id', '!=', $shift->id)->delete();

    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();

    expect($shift->assignments()->where('doctor_id', $preferred->id)->exists())->toBeFalse();
    expect($shift->assignments()->where('role', RosterAssignmentRole::Main)->count())->toBe(4);
});

it('preserves a Tuesday Main preference when another doctor can take Monday Night', function () {
    [$admin, $roster, $preferred, $other] = smartRoster();
    $night = smartShift('2026-10-05', 'weekday_night');
    $day = smartShift('2026-10-06', 'weekday_day');
    $roster->shifts()->whereNotIn('id', [$night->id, $day->id])->delete();
    Doctor::query()->whereNotIn('id', [$preferred->id, $other->id])->update(['is_active' => false]);
    ShiftType::query()->whereIn('id', [$night->shift_type_id, $day->shift_type_id])->update(['main_count' => 1, 'optional_count' => 0]);
    smartPreference($preferred, $day);

    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();

    expect($night->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id)->toBe($other->id);
    expect($day->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id)->toBe($preferred->id);
});

it('fills a required Night even when it consumes the only doctors future preference', function () {
    [$admin, $roster, $preferred] = smartRoster();
    $night = smartShift('2026-10-05', 'weekday_night');
    $day = smartShift('2026-10-06', 'weekday_day');
    $roster->shifts()->whereNotIn('id', [$night->id, $day->id])->delete();
    Doctor::query()->where('id', '!=', $preferred->id)->update(['is_active' => false]);
    ShiftType::query()->whereIn('id', [$night->shift_type_id, $day->shift_type_id])->update(['main_count' => 1, 'optional_count' => 0]);
    smartPreference($preferred, $day);

    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();

    expect($night->assignments()->where('role', RosterAssignmentRole::Main)->firstOrFail()->doctor_id)->toBe($preferred->id);
    expect($day->assignments()->where('role', RosterAssignmentRole::Main)->count())->toBe(0);
});

it('uses existing Draft assignments and preserves historical rows on repeat generation', function () {
    [$admin, $roster, $doctor] = smartRoster();
    $night = smartShift('2026-10-03', 'weekend_night');
    $day = smartShift('2026-10-06', 'weekday_day');
    $roster->shifts()->whereNotIn('id', [$night->id, $day->id])->delete();
    $history = smartHistory($doctor, ['closing_balance_minutes' => -300, 'actual_night_duty_count' => 2, 'optional_assignment_count' => 1]);
    $existing = $night->assignments()->create(['doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $request = smartPreference($doctor, $night);
    $ranker = smartRanker([$request], [$history]);

    expect($ranker->dimensions($doctor, $day, RosterAssignmentRole::Main, collect()))->toBe([1, 0, 660]);
    expect($ranker->dimensions($doctor, $day, RosterAssignmentRole::Optional, collect()))->toBe([0, 1, 660]);
    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    expect($existing->fresh()->doctor_id)->toBe($doctor->id);
    expect(DoctorMonthlyWorkload::query()->count())->toBe(1);
    expect($history->fresh()->closing_balance_minutes)->toBe(-300);
});

it('generates a full smart roster with preferences and historical fairness inputs', function () {
    [$admin, , $preferred, $other] = smartRoster();
    $day = smartShift('2026-10-06', 'weekday_day');
    smartPreference($preferred, $day);
    smartHistory($preferred, ['closing_balance_minutes' => 500, 'actual_night_duty_count' => 3, 'optional_assignment_count' => 2, 'worked_final_weekend' => true]);
    smartHistory($other, ['closing_balance_minutes' => -300, 'actual_night_duty_count' => 0, 'optional_assignment_count' => 0]);

    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();

    $assignments = RosterAssignment::query()->with('rosterShift.shiftType')->get();
    expect($day->assignments()->where('doctor_id', $preferred->id)->where('role', RosterAssignmentRole::Main)->exists())->toBeTrue();
    expect($assignments->where('role', RosterAssignmentRole::Main)->count())->toBeGreaterThan(0);
    expect($assignments->where('role', RosterAssignmentRole::Optional)->count())->toBeGreaterThan(0);
    foreach ($assignments->groupBy('roster_shift_id') as $shiftAssignments) {
        $shiftType = $shiftAssignments->firstOrFail()->rosterShift->shiftType;
        expect($shiftAssignments->where('role', RosterAssignmentRole::Main)->count())->toBeLessThanOrEqual($shiftType->main_count);
        expect($shiftAssignments->where('role', RosterAssignmentRole::Optional)->count())->toBeLessThanOrEqual($shiftType->optional_count);
    }
    expect(DoctorMonthlyWorkload::query()->count())->toBe(2);
});
