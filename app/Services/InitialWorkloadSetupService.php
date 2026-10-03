<?php

namespace App\Services;

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InitialWorkloadSetupService
{
    public function __construct(private DoctorMonthlyWorkloadService $workloads) {}

    public function assertAllowedPeriod(int $year, int $month): void
    {
        $earliest = DoctorMonthlyWorkload::query()->orderBy('year')->orderBy('month')->first();
        $firstRoster = Roster::query()->orderBy('year')->orderBy('month')->first();
        $target = $year * 12 + $month;
        if (($earliest !== null && ($earliest->year * 12 + $earliest->month !== $target || $earliest->source !== DoctorMonthlyWorkloadSource::ManualInitial))
            || ($firstRoster !== null && $firstRoster->year * 12 + $firstRoster->month !== $target + 1)) {
            throw ValidationException::withMessages(['baseline' => 'Initial Setup is limited to the month before the first roster.']);
        }
    }

    /** @param array<int, array{doctor_id: int, actual_hours: string, actual_night_duty_count: int, optional_assignment_count: int, worked_final_weekend: bool, most_recent_night_shift_at: string|null}> $entries */
    public function save(int $year, int $month, array $entries): void
    {
        DB::transaction(function () use ($year, $month, $entries): void {
            $existing = DoctorMonthlyWorkload::query()->lockForUpdate()->orderBy('year')->orderBy('month')->get();
            $this->assertAllowedPeriod($year, $month);
            $firstRoster = Roster::query()->orderBy('year')->orderBy('month')->first();
            $target = $year * 12 + $month;
            $firstRosterPeriod = $firstRoster === null ? null : $firstRoster->year * 12 + $firstRoster->month;
            if (($existing->isNotEmpty() && ($existing->first()->year * 12 + $existing->first()->month !== $target
                || $existing->where('year', $year)->where('month', $month)->contains(fn (DoctorMonthlyWorkload $row): bool => $row->source !== DoctorMonthlyWorkloadSource::ManualInitial)))
                || ($firstRosterPeriod !== null && $firstRosterPeriod !== $target + 1)) {
                throw ValidationException::withMessages(['baseline' => 'Initial Setup is limited to the month before the first roster.']);
            }
            $doctors = Doctor::query()->get()->keyBy('id');
            if (count($entries) !== $doctors->count() || count(array_unique(array_column($entries, 'doctor_id'))) !== $doctors->count()) {
                throw ValidationException::withMessages(['baseline' => 'Enter history for every doctor exactly once.']);
            }
            $facts = [];
            foreach ($entries as $entry) {
                if (! $doctors->has($entry['doctor_id'])) {
                    throw ValidationException::withMessages(['baseline' => 'The baseline contains an unknown doctor.']);
                }
                $night = $entry['most_recent_night_shift_at'];
                if (($entry['actual_night_duty_count'] > 0 && $night === null) || ($entry['actual_night_duty_count'] === 0 && $night !== null)
                    || ($night !== null && CarbonImmutable::parse($night)->format('Y-n') !== "$year-$month")) {
                    throw ValidationException::withMessages(['baseline' => 'The most recent Night must match the baseline month and Night count.']);
                }
                $facts[] = [
                    'doctor_id' => $entry['doctor_id'],
                    'actual_worked_minutes' => (int) round((float) $entry['actual_hours'] * 60, 0, PHP_ROUND_HALF_UP),
                    'actual_night_duty_count' => $entry['actual_night_duty_count'],
                    'optional_assignment_count' => $entry['optional_assignment_count'],
                    'worked_final_weekend' => $entry['worked_final_weekend'],
                    'most_recent_night_shift_at' => $night,
                    'is_month_excluded' => false,
                    'opening_balance_minutes' => 0,
                ];
            }
            foreach ($this->workloads->balances($facts)['rows'] as $row) {
                $doctorId = $row['doctor_id'];
                unset($row['doctor_id']);
                DoctorMonthlyWorkload::query()->updateOrCreate(
                    ['doctor_id' => $doctorId, 'year' => $year, 'month' => $month],
                    [...$row, 'roster_id' => null, 'source' => DoctorMonthlyWorkloadSource::ManualInitial],
                );
            }
            $this->workloads->recalculateLater($year, $month);
        });
    }
}
