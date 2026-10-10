<?php

namespace App\Services;

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterStatus;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RosterPlanningHistoryService
{
    public function __construct(
        private DoctorMonthlyWorkloadService $workloads,
    ) {}

    /** @return Collection<int, DoctorMonthlyWorkload> */
    public function forMonth(int $year, int $month, bool $requireFinal = true): Collection
    {
        $source = CarbonImmutable::create($year, $month, 1)->subMonth();
        $roster = Roster::query()->where('year', $source->year)->where('month', $source->month)->first();
        $rows = DoctorMonthlyWorkload::query()->where('year', $source->year)->where('month', $source->month)->get();

        if ($roster === null) {
            return $rows->where('source', DoctorMonthlyWorkloadSource::ManualInitial)->keyBy('doctor_id');
        }
        if ($roster->status !== RosterStatus::Final) {
            if (! $requireFinal) {
                return collect();
            }
            throw ValidationException::withMessages(['roster' => "Finalize {$source->format('F Y')} before generating ".CarbonImmutable::create($year, $month, 1)->format('F Y').'.']);
        }
        if ($roster->actual_work_confirmed_at !== null) {
            return $rows->where('source', DoctorMonthlyWorkloadSource::System)->where('roster_id', $roster->id)->keyBy('doctor_id');
        }

        return $this->fromPlannedRoster($roster);
    }

    /** @return Collection<int, DoctorMonthlyWorkload> */
    private function fromPlannedRoster(Roster $roster): Collection
    {
        $openings = $this->forMonth($roster->year, $roster->month, false);
        $doctorIds = DoctorMonthlyParticipation::query()
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->where('roster_id', $roster->id)
            ->pluck('doctor_id')
            ->flip();

        return collect($this->workloads->preview($roster, $openings)['rows'])
            ->filter(fn (array $row): bool => $doctorIds->has($row['doctor_id']))
            ->mapWithKeys(function (array $row) use ($roster): array {
                $doctorId = $row['doctor_id'];
                unset($row['doctor_id']);
                unset($row['is_participating']);
                unset($row['name'], $row['short_code']);

                return [$doctorId => new DoctorMonthlyWorkload([...$row, 'doctor_id' => $doctorId, 'year' => $roster->year, 'month' => $roster->month])];
            });
    }

    public function fingerprint(int $year, int $month): string
    {
        return $this->fingerprintRows($this->forMonth($year, $month, false));
    }

    /** @param Collection<int, DoctorMonthlyWorkload> $history */
    public function fingerprintRows(Collection $history): string
    {
        $rows = $history->map(fn (DoctorMonthlyWorkload $row): array => [
            'doctor_id' => $row->doctor_id,
            'actual_worked_minutes' => $row->actual_worked_minutes,
            'actual_night_duty_count' => $row->actual_night_duty_count,
            'optional_assignment_count' => $row->optional_assignment_count,
            'worked_final_weekend' => $row->worked_final_weekend,
            'most_recent_night_shift_at' => $row->most_recent_night_shift_at?->toDateTimeString(),
            'opening_balance_minutes' => $row->opening_balance_minutes,
            'monthly_adjustment_minutes' => $row->monthly_adjustment_minutes,
            'closing_balance_minutes' => $row->closing_balance_minutes,
        ])->sortBy('doctor_id')->values()->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
