<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RequestIntervalService;
use App\Services\WeekendPairCoordination;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\November2026DoctorRequestsSeeder;
use Database\Seeders\October2026DoctorRequestsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Validation\ValidationException;

function prepareNovemberDoctorRequests(): void
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
}

function runNovemberDoctorRequestsSeeder(): void
{
    app(November2026DoctorRequestsSeeder::class)->run(
        app(WeekendPairCoordination::class),
        app(RequestIntervalService::class),
    );
}

it('seeds twelve full-day requests, six date-specific preferences, two exclusions, and Thisaras Thursday Night preference', function () {
    prepareNovemberDoctorRequests();

    runNovemberDoctorRequestsSeeder();

    $actualRequests = DoctorRequest::query()->with(['doctor', 'shiftType'])->get()
        ->map(fn (DoctorRequest $request): array => [
            $request->doctor->short_code,
            $request->request_date->toDateString(),
            $request->request_type,
            $request->shiftType?->code,
        ])->sort()->values()->all();
    expect($actualRequests)->toBe([
        ['A', '2026-11-02', DoctorRequestType::DayOff, null],
        ['A', '2026-11-09', DoctorRequestType::DayOff, null],
        ['A', '2026-11-16', DoctorRequestType::DayOff, null],
        ['G', '2026-11-12', DoctorRequestType::PreferredWork, 'weekday_night'],
        ['G', '2026-11-19', DoctorRequestType::PreferredWork, 'weekday_night'],
        ['I', '2026-11-05', DoctorRequestType::DayOff, null],
        ['I', '2026-11-06', DoctorRequestType::DayOff, null],
        ['L', '2026-11-01', DoctorRequestType::DayOff, null],
        ['L', '2026-11-05', DoctorRequestType::DayOff, null],
        ['L', '2026-11-06', DoctorRequestType::DayOff, null],
        ['L', '2026-11-09', DoctorRequestType::DayOff, null],
        ['R', '2026-11-24', DoctorRequestType::DayOff, null],
        ['R', '2026-11-25', DoctorRequestType::DayOff, null],
        ['R', '2026-11-26', DoctorRequestType::DayOff, null],
        ['T', '2026-11-05', DoctorRequestType::PreferredWork, 'weekday_night'],
        ['T', '2026-11-12', DoctorRequestType::PreferredWork, 'weekday_night'],
        ['T', '2026-11-19', DoctorRequestType::PreferredWork, 'weekday_night'],
        ['T', '2026-11-26', DoctorRequestType::PreferredWork, 'weekday_night'],
    ]);
    expect(DoctorRequest::query()->where('request_type', DoctorRequestType::DayOff)->count())->toBe(12)
        ->and(DoctorRequest::query()->where('request_type', DoctorRequestType::PreferredWork)->whereNotNull('shift_type_id')->count())->toBe(6)
        ->and(DoctorRequest::query()->where('request_type', DoctorRequestType::DayOff)->whereNull('shift_type_id')->count())->toBe(12);

    $actualExclusions = DoctorMonthlyExclusion::query()->with('doctor')->get()
        ->map(fn (DoctorMonthlyExclusion $exclusion): array => [
            $exclusion->doctor->short_code,
            $exclusion->year,
            $exclusion->month,
        ])->sort()->values()->all();
    expect($actualExclusions)->toBe([
        ['M', 2026, 11],
        ['U', 2026, 11],
    ]);

    $preference = DoctorMonthlyWeekdayPreference::query()->with(['doctor', 'shiftType'])->firstOrFail();
    expect([$preference->doctor->short_code, $preference->year, $preference->month, $preference->shiftType->code, $preference->weekday])
        ->toBe(['T', 2026, 11, 'weekday_night', 4]);
});

it('preserves matching records and does not duplicate them on repeat execution', function () {
    prepareNovemberDoctorRequests();
    $lasantha = Doctor::query()->where('short_code', 'L')->firstOrFail();
    $mareena = Doctor::query()->where('short_code', 'M')->firstOrFail();
    $thisara = Doctor::query()->where('short_code', 'T')->firstOrFail();
    $ganga = Doctor::query()->where('short_code', 'G')->firstOrFail();
    $weekdayNight = ShiftType::query()->where('code', 'weekday_night')->firstOrFail();
    $request = DoctorRequest::query()->create([
        'doctor_id' => $lasantha->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-11-01',
        'shift_type_id' => null,
        'note' => 'preserve this note',
    ]);
    $matchingPreferredWorkRequest = DoctorRequest::query()->create([
        'doctor_id' => $ganga->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => '2026-11-12',
        'shift_type_id' => $weekdayNight->id,
        'note' => 'preserve this preferred work request',
    ]);
    $exclusion = DoctorMonthlyExclusion::query()->create([
        'doctor_id' => $mareena->id,
        'year' => 2026,
        'month' => 11,
        'note' => 'preserve this note',
    ]);
    $preference = DoctorMonthlyWeekdayPreference::query()->create([
        'doctor_id' => $thisara->id,
        'year' => 2026,
        'month' => 11,
        'shift_type_id' => $weekdayNight->id,
        'weekday' => 4,
    ]);
    $requestCreatedAt = $request->created_at->toDateTimeString();
    $matchingPreferredWorkRequestId = $matchingPreferredWorkRequest->id;
    $exclusionCreatedAt = $exclusion->created_at->toDateTimeString();
    $preferenceCreatedAt = $preference->created_at->toDateTimeString();

    runNovemberDoctorRequestsSeeder();
    runNovemberDoctorRequestsSeeder();

    expect(DoctorRequest::query()->count())->toBe(18)
        ->and(DoctorRequest::query()->where('request_type', DoctorRequestType::DayOff)->count())->toBe(12)
        ->and(DoctorRequest::query()->where('request_type', DoctorRequestType::PreferredWork)->count())->toBe(6)
        ->and(DoctorMonthlyExclusion::query()->where('year', 2026)->where('month', 11)->count())->toBe(2)
        ->and(DoctorMonthlyWeekdayPreference::query()->where('year', 2026)->where('month', 11)->count())->toBe(1);
    expect($request->fresh()->note)->toBe('preserve this note')
        ->and($request->fresh()->created_at->toDateTimeString())->toBe($requestCreatedAt);
    expect($matchingPreferredWorkRequest->fresh()->id)->toBe($matchingPreferredWorkRequestId)
        ->and($matchingPreferredWorkRequest->fresh()->note)->toBe('preserve this preferred work request')
        ->and($matchingPreferredWorkRequest->fresh()->shift_type_id)->toBe($weekdayNight->id);
    expect($exclusion->fresh()->note)->toBe('preserve this note')
        ->and($exclusion->fresh()->created_at->toDateTimeString())->toBe($exclusionCreatedAt);
    expect($preference->fresh()->created_at->toDateTimeString())->toBe($preferenceCreatedAt);
});

it('rejects missing required doctors or shift types before inserting records', function (string $missingRecord) {
    prepareNovemberDoctorRequests();
    if ($missingRecord === 'doctor') {
        Doctor::query()->where('short_code', 'L')->delete();
    } else {
        ShiftType::query()->where('code', 'weekday_night')->delete();
    }

    expect(fn () => runNovemberDoctorRequestsSeeder())->toThrow(ValidationException::class);

    expect(DoctorRequest::query()->count())->toBe(0)
        ->and(DoctorMonthlyExclusion::query()->count())->toBe(0)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(0);
})->with([
    'doctor' => 'doctor',
    'shift type' => 'shift_type',
]);

it('rejects conflicting existing requests or exclusions without partial November inserts', function (string $conflictType) {
    prepareNovemberDoctorRequests();
    $lasantha = Doctor::query()->where('short_code', 'L')->firstOrFail();
    $conflictingRequest = null;
    if ($conflictType === 'request') {
        $weekendNight = ShiftType::query()->where('code', 'weekend_night')->firstOrFail();
        $conflictingRequest = DoctorRequest::query()->create([
            'doctor_id' => $lasantha->id,
            'request_type' => DoctorRequestType::DayOff,
            'request_date' => '2026-11-01',
            'shift_type_id' => $weekendNight->id,
            'note' => 'keep conflicting data for review',
        ]);
    } else {
        DoctorMonthlyExclusion::query()->create([
            'doctor_id' => $lasantha->id,
            'year' => 2026,
            'month' => 11,
        ]);
    }

    $expectedMessage = $conflictType === 'request' ? 'overlaps the expected November request' : 'already has a monthly exclusion';
    expect(fn () => runNovemberDoctorRequestsSeeder())->toThrow(ValidationException::class, $expectedMessage);

    expect(DoctorRequest::query()->count())->toBe($conflictType === 'request' ? 1 : 0)
        ->and($conflictingRequest?->fresh()->note)->toBe($conflictType === 'request' ? 'keep conflicting data for review' : null)
        ->and(DoctorMonthlyExclusion::query()->count())->toBe($conflictType === 'request' ? 0 : 1)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(0);
})->with([
    'overlapping request' => 'request',
    'request doctor exclusion' => 'exclusion',
]);

it('does not add data to a Final or generated or assigned November roster', function (string $state) {
    prepareNovemberDoctorRequests();
    $roster = Roster::query()->create([
        'year' => 2026,
        'month' => 11,
        'status' => $state === 'final' ? RosterStatus::Final : RosterStatus::Draft,
        'last_generated_at' => $state === 'generated draft' ? now() : null,
    ]);
    if ($state === 'assigned draft') {
        $shift = RosterShift::query()->create([
            'roster_id' => $roster->id,
            'shift_type_id' => ShiftType::query()->where('code', 'weekday_day')->value('id'),
            'shift_date' => '2026-11-02',
        ]);
        RosterAssignment::query()->create([
            'roster_shift_id' => $shift->id,
            'doctor_id' => Doctor::query()->where('short_code', 'A')->value('id'),
            'role' => RosterAssignmentRole::Main,
            'slot_number' => 1,
        ]);
    }

    expect(fn () => runNovemberDoctorRequestsSeeder())->toThrow(ValidationException::class);

    expect(DoctorRequest::query()->count())->toBe(0)
        ->and(DoctorMonthlyExclusion::query()->count())->toBe(0)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(0);
})->with([
    'final' => 'final',
    'generated draft' => 'generated draft',
    'assigned draft' => 'assigned draft',
]);

it('leaves the finalized October roster and all October request data unchanged', function () {
    prepareNovemberDoctorRequests();
    app(October2026DoctorRequestsSeeder::class)->run(app(RequestIntervalService::class));
    $roster = Roster::query()->create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final]);
    $shift = RosterShift::query()->create([
        'roster_id' => $roster->id,
        'shift_type_id' => ShiftType::query()->where('code', 'weekend_night')->value('id'),
        'shift_date' => '2026-10-31',
    ]);
    RosterAssignment::query()->create([
        'roster_shift_id' => $shift->id,
        'doctor_id' => Doctor::query()->where('short_code', 'L')->value('id'),
        'role' => RosterAssignmentRole::Main,
        'slot_number' => 1,
    ]);

    $octoberRequests = DoctorRequest::query()->whereBetween('request_date', ['2026-10-01', '2026-10-31'])->orderBy('id')->get()->toArray();
    $octoberExclusions = DoctorMonthlyExclusion::query()->where('year', 2026)->where('month', 10)->orderBy('id')->get()->toArray();
    $octoberPreferences = DoctorMonthlyWeekdayPreference::query()->where('year', 2026)->where('month', 10)->orderBy('id')->get()->toArray();
    $octoberRoster = Roster::query()->where('year', 2026)->where('month', 10)->firstOrFail()->toArray();
    $octoberShifts = RosterShift::query()->where('roster_id', $roster->id)->orderBy('id')->get()->toArray();
    $octoberAssignments = RosterAssignment::query()->whereIn('roster_shift_id', [$shift->id])->orderBy('id')->get()->toArray();

    runNovemberDoctorRequestsSeeder();

    expect(DoctorRequest::query()->whereBetween('request_date', ['2026-10-01', '2026-10-31'])->orderBy('id')->get()->toArray())->toBe($octoberRequests)
        ->and(DoctorMonthlyExclusion::query()->where('year', 2026)->where('month', 10)->orderBy('id')->get()->toArray())->toBe($octoberExclusions)
        ->and(DoctorMonthlyWeekdayPreference::query()->where('year', 2026)->where('month', 10)->orderBy('id')->get()->toArray())->toBe($octoberPreferences)
        ->and(Roster::query()->where('year', 2026)->where('month', 10)->firstOrFail()->toArray())->toBe($octoberRoster)
        ->and(RosterShift::query()->where('roster_id', $roster->id)->orderBy('id')->get()->toArray())->toBe($octoberShifts)
        ->and(RosterAssignment::query()->whereIn('roster_shift_id', [$shift->id])->orderBy('id')->get()->toArray())->toBe($octoberAssignments);
});

it('keeps the November 1 full-day request separate from Optional assignment eligibility', function () {
    prepareNovemberDoctorRequests();
    runNovemberDoctorRequestsSeeder();
    $lasantha = Doctor::query()->where('short_code', 'L')->firstOrFail();
    $dayOff = DoctorRequest::query()->whereBelongsTo($lasantha)->whereDate('request_date', '2026-11-01')->get();
    $weekendDay = ShiftType::query()->where('code', 'weekend_day')->firstOrFail();
    $candidate = new RosterShift(['shift_date' => '2026-11-01']);
    $candidate->setRelation('shiftType', $weekendDay);
    $eligibility = app(DoctorAssignmentEligibilityService::class);

    expect($dayOff)->toHaveCount(1)
        ->and($dayOff->first()->shift_type_id)->toBeNull()
        ->and($eligibility->roleAwareConflicts($lasantha, $candidate, RosterAssignmentRole::Main, false, true, $dayOff, collect()))
        ->toContain('day_off_overlap')
        ->and($eligibility->roleAwareConflicts($lasantha, $candidate, RosterAssignmentRole::Optional, false, true, $dayOff, collect()))
        ->toBe([]);
});

it('rejects execution outside local and testing environments without writing data', function () {
    prepareNovemberDoctorRequests();
    app()->detectEnvironment(fn (): string => 'staging');

    expect(fn () => runNovemberDoctorRequestsSeeder())->toThrow(RuntimeException::class, 'November 2026 doctor request seeding is limited to local and testing environments.');

    expect(DoctorRequest::query()->count())->toBe(0)
        ->and(DoctorMonthlyExclusion::query()->count())->toBe(0)
        ->and(DoctorMonthlyWeekdayPreference::query()->count())->toBe(0);
});
