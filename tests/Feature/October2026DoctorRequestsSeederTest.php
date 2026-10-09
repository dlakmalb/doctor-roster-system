<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RequestIntervalService;
use App\Services\RosterCandidateRanker;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\October2026DoctorRequestsSeeder;
use Database\Seeders\September2026HistoricalBaselineSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

function prepareOctoberDoctorRequests(): void
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
}

function runOctoberDoctorRequestsSeeder(): void
{
    app(October2026DoctorRequestsSeeder::class)->run(app(RequestIntervalService::class));
}

it('seeds the confirmed October request and monthly rule counts by doctor short code', function () {
    prepareOctoberDoctorRequests();
    $statusesBefore = Doctor::query()->pluck('is_active', 'short_code')->all();

    runOctoberDoctorRequestsSeeder();

    expect(DoctorRequest::query()->where('request_type', DoctorRequestType::DayOff)->count())->toBe(20)
        ->and(DoctorRequest::query()->where('request_type', DoctorRequestType::PreferredWork)->count())->toBe(1)
        ->and(DoctorMonthlyShiftRestriction::query()->where('year', 2026)->where('month', 10)->count())->toBe(3)
        ->and(DoctorMonthlyWeekdayPreference::query()->where('year', 2026)->where('month', 10)->count())->toBe(4)
        ->and(Doctor::query()->pluck('is_active', 'short_code')->all())->toBe($statusesBefore);

    $sanath = Doctor::query()->where('short_code', 'S')->firstOrFail();
    $sanathRequests = $sanath->requests()->orderBy('request_date')->get();
    expect($sanathRequests->map(fn (DoctorRequest $request): array => [$request->request_date->toDateString(), $request->request_type->value, $request->shiftType?->code])->all())
        ->toBe([
            ['2026-10-02', 'day_off', 'weekday_night'],
            ['2026-10-09', 'preferred_work', 'weekday_night'],
            ['2026-10-10', 'day_off', 'weekend_night'],
        ]);

    $nuwan = Doctor::query()->where('short_code', 'N')->firstOrFail();
    expect($nuwan->monthlyShiftRestrictions()->where('year', 2026)->where('month', 10)->with('shiftType')->get()->pluck('shiftType.code')->sort()->values()->all())
        ->toBe(['weekday_evening', 'weekday_night', 'weekend_night']);

    $preferences = DoctorMonthlyWeekdayPreference::query()->where('year', 2026)->where('month', 10)->with(['doctor', 'shiftType'])->get()
        ->map(fn (DoctorMonthlyWeekdayPreference $preference): array => [$preference->doctor->short_code, $preference->shiftType->code, $preference->weekday])->sort()->values()->all();
    expect($preferences)->toBe([
        ['G', 'weekday_evening', 4],
        ['G', 'weekday_night', 4],
        ['T', 'weekday_night', 4],
        ['T', 'weekend_night', 7],
    ]);
    expect(Doctor::query()->where('short_code', 'H')->firstOrFail()->requests()->count())->toBe(0);
});

it('persists every confirmed October date-specific request exactly', function () {
    prepareOctoberDoctorRequests();

    runOctoberDoctorRequestsSeeder();

    $actual = DoctorRequest::query()->with(['doctor', 'shiftType'])->get()
        ->map(fn (DoctorRequest $request): array => [
            $request->doctor->short_code,
            $request->request_date->toDateString(),
            $request->request_type->value,
            $request->shiftType?->code,
        ])->sort()->values()->all();
    $expected = [
        ['B', '2026-10-01', 'day_off', null],
        ['B', '2026-10-09', 'day_off', null],
        ['G', '2026-10-15', 'day_off', null],
        ['I', '2026-10-07', 'day_off', null],
        ['I', '2026-10-08', 'day_off', null],
        ['I', '2026-10-09', 'day_off', null],
        ['K', '2026-10-01', 'day_off', null],
        ['K', '2026-10-02', 'day_off', null],
        ['L', '2026-10-09', 'day_off', null],
        ['L', '2026-10-12', 'day_off', null],
        ['L', '2026-10-13', 'day_off', null],
        ['M', '2026-10-06', 'day_off', null],
        ['M', '2026-10-07', 'day_off', null],
        ['M', '2026-10-08', 'day_off', null],
        ['S', '2026-10-02', 'day_off', 'weekday_night'],
        ['S', '2026-10-09', 'preferred_work', 'weekday_night'],
        ['S', '2026-10-10', 'day_off', 'weekend_night'],
        ['T', '2026-10-12', 'day_off', null],
        ['U', '2026-10-02', 'day_off', null],
        ['U', '2026-10-17', 'day_off', null],
        ['U', '2026-10-18', 'day_off', null],
    ];
    sort($expected);

    expect($actual)->toBe($expected);
});

it('preserves matching timestamps and unrelated October data when rerun', function () {
    prepareOctoberDoctorRequests();
    runOctoberDoctorRequestsSeeder();
    $request = DoctorRequest::query()->whereHas('doctor', fn ($query) => $query->where('short_code', 'B'))->firstOrFail();
    $request->forceFill(['note' => 'manual note', 'created_at' => '2026-09-01 12:00:00'])->save();
    $createdAt = $request->fresh()->created_at->toDateTimeString();
    $unrelatedDoctor = Doctor::query()->where('short_code', 'A')->firstOrFail();
    DoctorRequest::query()->create(['doctor_id' => $unrelatedDoctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-20', 'shift_type_id' => null, 'note' => 'unrelated']);

    runOctoberDoctorRequestsSeeder();

    expect($request->fresh()->note)->toBe('manual note')
        ->and($request->fresh()->created_at->toDateTimeString())->toBe($createdAt)
        ->and(DoctorRequest::query()->where('note', 'unrelated')->exists())->toBeTrue()
        ->and(DoctorRequest::query()->count())->toBe(22);
});

it('leaves September workload history and October assignments untouched', function () {
    prepareOctoberDoctorRequests();
    $this->seed(September2026HistoricalBaselineSeeder::class);
    $septemberBefore = DB::table('doctor_monthly_workloads')->where('year', 2026)->where('month', 9)->orderBy('doctor_id')->get()->map(fn ($row): array => (array) $row)->all();

    runOctoberDoctorRequestsSeeder();

    expect(DB::table('doctor_monthly_workloads')->where('year', 2026)->where('month', 9)->orderBy('doctor_id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($septemberBefore)
        ->and(RosterAssignment::query()->whereHas('rosterShift.roster', fn ($query) => $query->where('year', 2026)->where('month', 10))->count())->toBe(0);
});

it('completes compatible partial data without replacing existing rows', function () {
    prepareOctoberDoctorRequests();
    $buddhima = Doctor::query()->where('short_code', 'B')->firstOrFail();
    DoctorRequest::query()->create(['doctor_id' => $buddhima->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-01', 'shift_type_id' => null, 'note' => 'keep']);

    runOctoberDoctorRequestsSeeder();

    expect(DoctorRequest::query()->where('request_type', DoctorRequestType::DayOff)->count())->toBe(20)
        ->and(DoctorRequest::query()->where('note', 'keep')->count())->toBe(1)
        ->and(DoctorMonthlyShiftRestriction::query()->count())->toBe(3)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(4);
});

it('rejects conflicting existing requests and rolls back all October inserts', function () {
    prepareOctoberDoctorRequests();
    $sanath = Doctor::query()->where('short_code', 'S')->firstOrFail();
    DoctorRequest::query()->create(['doctor_id' => $sanath->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-09', 'shift_type_id' => null]);

    expect(fn () => runOctoberDoctorRequestsSeeder())->toThrow(ValidationException::class);

    expect(DoctorRequest::query()->count())->toBe(1)
        ->and(DoctorMonthlyShiftRestriction::query()->count())->toBe(0)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(0);
});

it('rejects preexisting restrictions that conflict with seeded preferences without inserting data', function (bool $completeDataset) {
    prepareOctoberDoctorRequests();
    if ($completeDataset) {
        runOctoberDoctorRequestsSeeder();
    }
    $countsBefore = [DoctorRequest::query()->count(), DoctorMonthlyShiftRestriction::query()->count(), DoctorMonthlyWeekdayPreference::query()->count()];
    $thisara = Doctor::query()->where('short_code', 'T')->firstOrFail();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    DoctorMonthlyShiftRestriction::query()->create(['doctor_id' => $thisara->id, 'year' => 2026, 'month' => 10, 'shift_type_id' => $weekdayNight->id]);
    $restrictionCount = DoctorMonthlyShiftRestriction::query()->count();

    expect(fn () => runOctoberDoctorRequestsSeeder())->toThrow(ValidationException::class, 'conflicts with the Thursday preference');

    expect([DoctorRequest::query()->count(), DoctorMonthlyShiftRestriction::query()->count(), DoctorMonthlyWeekdayPreference::query()->count()])
        ->toBe([$countsBefore[0], $restrictionCount, $countsBefore[2]])
        ->and(DoctorMonthlyShiftRestriction::query()->where('doctor_id', $thisara->id)->where('shift_type_id', $weekdayNight->id)->exists())->toBeTrue();
})->with([false, true]);

it('allows Sanaths adjacent night requests when their intervals do not overlap', function () {
    prepareOctoberDoctorRequests();
    runOctoberDoctorRequestsSeeder();

    $requests = DoctorRequest::query()->whereHas('doctor', fn ($query) => $query->where('short_code', 'S'))->with('shiftType')->get();
    $intervals = app(RequestIntervalService::class);
    $preferred = $requests->firstWhere('request_type', DoctorRequestType::PreferredWork);
    $saturdayDayOff = $requests->first(fn (DoctorRequest $request): bool => $request->request_date->toDateString() === '2026-10-10');
    expect($intervals->overlaps($intervals->forRequest($preferred), $intervals->forRequest($saturdayDayOff)))->toBeFalse();
});

it('blocks Nuwan only on restricted October shifts and leaves his Day shifts eligible', function () {
    prepareOctoberDoctorRequests();
    runOctoberDoctorRequestsSeeder();
    $nuwan = Doctor::query()->where('short_code', 'N')->firstOrFail();
    $restrictionIds = $nuwan->monthlyShiftRestrictions()->where('year', 2026)->where('month', 10)->pluck('shift_type_id')->all();
    $eligibility = app(DoctorAssignmentEligibilityService::class);

    foreach (['weekday_evening', 'weekday_night', 'weekend_night'] as $code) {
        $shiftType = ShiftType::query()->where('code', $code)->firstOrFail();
        $shiftDate = $code === 'weekend_night' ? '2026-10-03' : '2026-10-05';
        $candidate = new RosterShift(['shift_date' => $shiftDate]);
        $candidate->setRelation('shiftType', $shiftType);
        expect($eligibility->conflicts($nuwan, $candidate, false, collect(), collect(), null, true, in_array($shiftType->id, $restrictionIds, true)))->toContain('monthly_shift_restriction');
    }
    foreach (['weekday_day', 'weekend_day'] as $code) {
        $shiftType = ShiftType::query()->where('code', $code)->firstOrFail();
        $shiftDate = $code === 'weekend_day' ? '2026-10-03' : '2026-10-05';
        $candidate = new RosterShift(['shift_date' => $shiftDate]);
        $candidate->setRelation('shiftType', $shiftType);
        expect($eligibility->conflicts($nuwan, $candidate, false, collect(), collect()))->toBe([]);
    }
});

it('blocks Sanaths Night-only Day-Off requests and leaves his other dates available', function () {
    prepareOctoberDoctorRequests();
    runOctoberDoctorRequestsSeeder();
    $sanath = Doctor::query()->where('short_code', 'S')->firstOrFail();
    $dayOffs = $sanath->requests()->where('request_type', DoctorRequestType::DayOff)->with('shiftType')->get();
    $eligibility = app(DoctorAssignmentEligibilityService::class);
    $fridayNightType = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $saturdayNightType = ShiftType::query()->where('code', 'weekend_night')->firstOrFail();
    $friday = new RosterShift(['shift_date' => '2026-10-02']);
    $friday->setRelation('shiftType', $fridayNightType);
    $saturday = new RosterShift(['shift_date' => '2026-10-10']);
    $saturday->setRelation('shiftType', $saturdayNightType);
    $otherDate = new RosterShift(['shift_date' => '2026-10-16']);
    $otherDate->setRelation('shiftType', $fridayNightType);

    expect($eligibility->conflicts($sanath, $friday, false, $dayOffs, collect()))->toContain('day_off_overlap')
        ->and($eligibility->conflicts($sanath, $saturday, false, $dayOffs, collect()))->toContain('day_off_overlap')
        ->and($eligibility->conflicts($sanath, $otherDate, false, $dayOffs, collect()))->toBe([]);
});

it('loads Thisara and Ganga monthly weekday preferences as soft ranking inputs', function () {
    prepareOctoberDoctorRequests();
    runOctoberDoctorRequestsSeeder();
    $preferences = DoctorMonthlyWeekdayPreference::query()->where('year', 2026)->where('month', 10)->get();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $weekdayEvening = ShiftType::query()->where('code', 'weekday_evening')->firstOrFail();
    $thursdayNight = new RosterShift(['shift_date' => '2026-10-01', 'shift_type_id' => $weekdayNight->id]);
    $thursdayNight->setAttribute('id', 1);
    $thursdayNight->setRelation('shiftType', $weekdayNight);
    $fridayNight = new RosterShift(['shift_date' => '2026-10-02', 'shift_type_id' => $weekdayNight->id]);
    $fridayNight->setAttribute('id', 2);
    $fridayNight->setRelation('shiftType', $weekdayNight);
    $thursdayEvening = new RosterShift(['shift_date' => '2026-10-01', 'shift_type_id' => $weekdayEvening->id]);
    $thursdayEvening->setAttribute('id', 3);
    $thursdayEvening->setRelation('shiftType', $weekdayEvening);
    $ranker = app(RosterCandidateRanker::class);
    $ranker->initialize(collect([$thursdayNight, $fridayNight, $thursdayEvening]), collect(), collect(), CarbonImmutable::parse('2026-10-01'), [], $preferences);
    $thisara = Doctor::query()->where('short_code', 'T')->firstOrFail();
    $ganga = Doctor::query()->where('short_code', 'G')->firstOrFail();

    expect(array_slice($ranker->dimensions($thisara, $thursdayNight, RosterAssignmentRole::Main, collect()), -1))->toBe([0])
        ->and(array_slice($ranker->dimensions($thisara, $fridayNight, RosterAssignmentRole::Main, collect()), -1))->toBe([1])
        ->and(array_slice($ranker->dimensions($ganga, $thursdayEvening, RosterAssignmentRole::Main, collect()), -1))->toBe([0])
        ->and(array_slice($ranker->dimensions($ganga, $thursdayNight, RosterAssignmentRole::Main, collect()), -1))->toBe([0]);
});

it('does not change existing October rules when the roster is Final or has assigned Draft work', function (string $state) {
    prepareOctoberDoctorRequests();
    $roster = Roster::query()->create(['year' => 2026, 'month' => 10, 'status' => $state === 'final' ? RosterStatus::Final : RosterStatus::Draft]);
    if ($state === 'draft') {
        $shift = RosterShift::query()->create(['roster_id' => $roster->id, 'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->value('id'), 'shift_date' => '2026-10-01']);
        RosterAssignment::query()->create(['roster_shift_id' => $shift->id, 'doctor_id' => Doctor::query()->where('short_code', 'B')->value('id'), 'role' => 'main', 'slot_number' => 1]);
    }

    expect(fn () => runOctoberDoctorRequestsSeeder())->toThrow(ValidationException::class);

    expect(DoctorRequest::query()->count())->toBe(0)
        ->and(DoctorMonthlyShiftRestriction::query()->count())->toBe(0)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(0);
})->with(['final', 'draft']);

it('rejects execution outside local and testing environments without writing data', function () {
    prepareOctoberDoctorRequests();
    app()->detectEnvironment(fn (): string => 'staging');

    expect(fn () => runOctoberDoctorRequestsSeeder())->toThrow(RuntimeException::class, 'October 2026 doctor request seeding is limited to local and testing environments.');

    expect(DoctorRequest::query()->count())->toBe(0)
        ->and(DoctorMonthlyShiftRestriction::query()->count())->toBe(0)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(0);
});
