<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\September2026HistoricalBaselineSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    app()->instance('september_baseline.original_environment', app()->environment());
});

afterEach(function (): void {
    $originalEnvironment = app()->make('september_baseline.original_environment');
    app()->detectEnvironment(fn (): string => $originalEnvironment);
    app()->forgetInstance('september_baseline.original_environment');
});

function septemberBaselineSeeder(): September2026HistoricalBaselineSeeder
{
    return app(September2026HistoricalBaselineSeeder::class);
}

it('calculates the confirmed September totals and timestamps from the source roster', function () {
    $this->seed(DoctorsSeeder::class);

    $facts = septemberBaselineSeeder()->calculateFacts(septemberBaselineSeeder()->sourceRoster());

    expect(array_sum(array_column($facts, 'hours')))->toBe(1900)
        ->and(array_sum(array_column($facts, 'nights')))->toBe(60)
        ->and(array_sum(array_column($facts, 'optional')))->toBe(122)
        ->and(array_keys(array_filter($facts, fn (array $fact): bool => $fact['final_weekend'])))->toBe(['N', 'S', 'K', 'E', 'G', 'M', 'U'])
        ->and($facts['N']['most_recent_night'])->toBe('2026-09-30 20:00:00')
        ->and($facts['S']['most_recent_night'])->toBe('2026-09-27 16:00:00')
        ->and($facts['H']['most_recent_night'])->toBe('2026-09-22 20:00:00')
        ->and($facts['T']['most_recent_night'])->toBe('2026-09-24 20:00:00')
        ->and($facts['K']['most_recent_night'])->toBe('2026-09-29 20:00:00')
        ->and($facts['E']['most_recent_night'])->toBe('2026-09-30 20:00:00')
        ->and($facts['G']['most_recent_night'])->toBe('2026-09-24 20:00:00')
        ->and($facts['A']['most_recent_night'])->toBe('2026-09-25 20:00:00')
        ->and($facts['M']['most_recent_night'])->toBe('2026-09-26 16:00:00')
        ->and($facts['R']['most_recent_night'])->toBe('2026-09-28 20:00:00')
        ->and($facts['B']['most_recent_night'])->toBe('2026-09-19 16:00:00')
        ->and($facts['L']['most_recent_night'])->toBe('2026-09-23 20:00:00')
        ->and($facts['U']['most_recent_night'])->toBe('2026-09-29 20:00:00')
        ->and($facts['I']['most_recent_night'])->toBe('2026-09-28 20:00:00')
        ->and($facts['C'])->toBe(['hours' => 0, 'nights' => 0, 'optional' => 0, 'final_weekend' => false, 'most_recent_night' => null]);
});

it('rejects unknown and duplicate same-day doctor assignments', function (string $group, string $value, string $message) {
    $this->seed(DoctorsSeeder::class);
    $roster = septemberBaselineSeeder()->sourceRoster();
    $roster['2026-09-01']['groups'][$group] = $value;

    expect(fn () => septemberBaselineSeeder()->calculateFacts($roster))
        ->toThrow(ValidationException::class, $message);
})->with([
    ['day', 'NHSX', 'Unknown doctor code X on 2026-09-01.'],
    ['day', 'NHSN', 'Doctor N is assigned more than once on 2026-09-01.'],
]);

it('saves all doctors through Initial Setup and leaves a matching second run unchanged', function () {
    $this->seed(DoctorsSeeder::class);
    Doctor::query()->where('short_code', 'H')->update(['is_active' => false]);
    $this->seed(September2026HistoricalBaselineSeeder::class);
    $before = DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->orderBy('doctor_id')->get()->toArray();

    expect($before)->toHaveCount(15);
    $this->assertDatabaseCount('doctor_monthly_participations', 15);
    expect(DoctorMonthlyParticipation::query()->where('year', 2026)->where('month', 9)->where('is_participating', true)->count())->toBe(14)
        ->and(DoctorMonthlyParticipation::query()->where('year', 2026)->where('month', 9)->where('doctor_id', Doctor::query()->where('short_code', 'H')->value('id'))->firstOrFail()->is_participating)->toBeTrue();

    $expected = [
        'N' => [130, 4, 11, true, '2026-09-30 20:00:00', -343], 'S' => [140, 4, 8, true, '2026-09-27 16:00:00', 257],
        'H' => [134, 4, 9, false, '2026-09-22 20:00:00', -103], 'T' => [134, 4, 9, false, '2026-09-24 20:00:00', -103],
        'K' => [132, 5, 9, true, '2026-09-29 20:00:00', -223], 'E' => [140, 5, 9, true, '2026-09-30 20:00:00', 257],
        'G' => [134, 4, 9, true, '2026-09-24 20:00:00', -103], 'A' => [140, 5, 7, false, '2026-09-25 20:00:00', 257],
        'M' => [134, 4, 9, true, '2026-09-26 16:00:00', -103], 'R' => [140, 4, 8, false, '2026-09-28 20:00:00', 257],
        'B' => [126, 4, 10, false, '2026-09-19 16:00:00', -583], 'L' => [136, 4, 9, false, '2026-09-23 20:00:00', 17],
        'U' => [140, 5, 8, true, '2026-09-29 20:00:00', 257], 'I' => [140, 4, 7, false, '2026-09-28 20:00:00', 257],
        'C' => [0, 0, 0, false, null, 0],
    ];
    foreach ($expected as $code => [$hours, $nights, $optional, $finalWeekend, $mostRecentNight, $closing]) {
        $row = DoctorMonthlyWorkload::query()->whereHas('doctor', fn ($query) => $query->where('short_code', $code))->where('year', 2026)->where('month', 9)->firstOrFail();
        expect([$row->actual_worked_minutes, $row->actual_night_duty_count, $row->optional_assignment_count, $row->worked_final_weekend, $row->most_recent_night_shift_at?->format('Y-m-d H:i:s'), $row->source->value, $row->opening_balance_minutes, $row->closing_balance_minutes])
            ->toBe([$hours * 60, $nights, $optional, $finalWeekend, $mostRecentNight, 'manual_initial', 0, $closing]);
    }

    $this->seed(September2026HistoricalBaselineSeeder::class);

    expect(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->orderBy('doctor_id')->get()->toArray())->toBe($before)
        ->and(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->where('source', DoctorMonthlyWorkloadSource::ManualInitial)->count())->toBe(15);
    $nuwan = DoctorMonthlyWorkload::query()->whereHas('doctor', fn ($query) => $query->where('short_code', 'N'))->where('year', 2026)->where('month', 9)->firstOrFail();
    $this->assertDatabaseHas('doctor_monthly_workloads', ['id' => $nuwan->id, 'actual_worked_minutes' => 7800, 'actual_night_duty_count' => 4, 'optional_assignment_count' => 11, 'worked_final_weekend' => true, 'opening_balance_minutes' => 0, 'closing_balance_minutes' => -343]);
    expect($nuwan->most_recent_night_shift_at->format('Y-m-d H:i:s'))->toBe('2026-09-30 20:00:00');
    $this->assertDatabaseHas('doctor_monthly_participations', ['doctor_id' => Doctor::query()->where('short_code', 'C')->value('id'), 'year' => 2026, 'month' => 9, 'is_participating' => false]);
    expect(DoctorMonthlyParticipation::query()->where('year', 2026)->where('month', 9)->where('doctor_id', Doctor::query()->where('short_code', 'C')->value('id'))->firstOrFail()->is_participating)->toBeFalse();
});

it('leaves matching September and later workload records unchanged on a rerun', function () {
    $this->seed(DoctorsSeeder::class);
    $this->seed(September2026HistoricalBaselineSeeder::class);
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    DoctorMonthlyWorkload::query()->create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'source' => DoctorMonthlyWorkloadSource::ManualInitial,
        'actual_worked_minutes' => 360,
    ]);
    $septemberBefore = DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->orderBy('doctor_id')->get()->toArray();
    $octoberBefore = DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 10)->orderBy('doctor_id')->get()->toArray();

    $this->seed(September2026HistoricalBaselineSeeder::class);

    expect(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->orderBy('doctor_id')->get()->toArray())->toBe($septemberBefore)
        ->and(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 10)->orderBy('doctor_id')->get()->toArray())->toBe($octoberBefore);
});

it('refuses partial or conflicting September history without changing it', function (string $kind) {
    $this->seed(DoctorsSeeder::class);
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    if ($kind === 'partial') {
        DoctorMonthlyWorkload::query()->create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 9, 'source' => DoctorMonthlyWorkloadSource::ManualInitial, 'actual_worked_minutes' => 1]);
    } else {
        $this->seed(September2026HistoricalBaselineSeeder::class);
        DoctorMonthlyWorkload::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 9)->update(['actual_worked_minutes' => 1]);
    }
    $originalRows = DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->get()->toArray();

    expect(fn () => $this->seed(September2026HistoricalBaselineSeeder::class))
        ->toThrow(ValidationException::class);

    expect(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)->get()->toArray())->toBe($originalRows);
})->with(['partial', 'conflicting']);

it('rejects a doctor population that does not match the historical source', function () {
    $this->seed(DoctorsSeeder::class);
    Doctor::query()->create(['short_code' => 'X', 'name' => 'Dr Extra', 'is_active' => true]);

    expect(fn () => $this->seed(September2026HistoricalBaselineSeeder::class))
        ->toThrow(ValidationException::class, 'The doctor population must contain each expected September code exactly once and no other doctors.');

    $this->assertDatabaseCount('doctor_monthly_workloads', 0);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
});

it('rejects execution outside local and testing environments before writing history', function () {
    $this->seed(DoctorsSeeder::class);
    app()->detectEnvironment(fn (): string => 'staging');

    expect(fn () => $this->seed(September2026HistoricalBaselineSeeder::class))
        ->toThrow(RuntimeException::class, 'September 2026 historical baseline seeding is limited to local and testing environments.');

    $this->assertDatabaseCount('doctor_monthly_workloads', 0);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
});

it('refuses to recalculate later workload periods', function () {
    $this->seed(DoctorsSeeder::class);
    $doctor = Doctor::query()->where('short_code', 'N')->firstOrFail();
    DoctorMonthlyWorkload::query()->create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'source' => DoctorMonthlyWorkloadSource::ManualInitial, 'actual_worked_minutes' => 1]);

    expect(fn () => $this->seed(September2026HistoricalBaselineSeeder::class))
        ->toThrow(ValidationException::class, 'Later workload history exists; refusing to recalculate unrelated months while installing the September baseline.');

    $this->assertDatabaseCount('doctor_monthly_participations', 0);
    $this->assertDatabaseHas('doctor_monthly_workloads', ['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10, 'actual_worked_minutes' => 1]);
});
