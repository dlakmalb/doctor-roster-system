<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\ShiftType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MonthlySetupService
{
    public function __construct(private RequestIntervalService $intervals) {}

    /** @return array<string, mixed> */
    public function build(int $year, int $monthNumber): array
    {
        $month = CarbonImmutable::create($year, $monthNumber, 1)->startOfMonth();
        $monthEnd = $month->endOfMonth();
        $activeDoctors = Doctor::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'short_code']);
        $requests = DoctorRequest::query()
            ->with(['doctor:id,name,short_code,is_active', 'shiftType:id,code,name,start_time,end_time,is_overnight,is_active'])
            ->whereBetween('request_date', [$month, $monthEnd])
            ->orderBy('request_date')
            ->orderBy('doctor_id')
            ->get();
        $exclusions = DoctorMonthlyExclusion::query()
            ->with('doctor:id,name,short_code,is_active')
            ->where('year', $year)
            ->where('month', $monthNumber)
            ->orderBy('doctor_id')
            ->get();
        $shiftTypes = ShiftType::query()
            ->where('is_active', true)
            ->whereIn('code', ['weekday_day', 'weekday_evening', 'weekday_night', 'weekend_day', 'weekend_night'])
            ->orderBy('start_time')
            ->get(['id', 'code', 'name', 'start_time', 'end_time', 'main_count', 'optional_count', 'is_overnight']);

        $dayOffRequests = $requests->where('request_type', DoctorRequestType::DayOff);
        $preferredWorkRequests = $requests->where('request_type', DoctorRequestType::PreferredWork);
        $warnings = collect()
            ->concat($this->lateWarnings($dayOffRequests, $month))
            ->concat($this->dayOffLimitWarnings($dayOffRequests))
            ->concat($this->staffingRiskWarnings($month, $activeDoctors, $exclusions, $shiftTypes));

        return [
            'month' => [
                'year' => $year,
                'month' => $monthNumber,
                'label' => $month->format('F Y'),
                'previous' => ['year' => $month->subMonth()->year, 'month' => $month->subMonth()->month],
                'next' => ['year' => $month->addMonth()->year, 'month' => $month->addMonth()->month],
            ],
            'rosterStatus' => Roster::where('year', $year)->where('month', $monthNumber)->value('status') ?? 'not_started',
            'doctors' => $activeDoctors->map(fn (Doctor $doctor): array => [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'short_code' => $doctor->short_code,
            ])->values(),
            'shiftTypes' => $shiftTypes->map(fn (ShiftType $shiftType): array => [
                'id' => $shiftType->id,
                'code' => $shiftType->code,
                'name' => $shiftType->name,
                'start_time' => $shiftType->start_time,
                'end_time' => $shiftType->end_time,
                'is_overnight' => $shiftType->is_overnight,
            ])->values(),
            'dayOffRequests' => $dayOffRequests->map(fn (DoctorRequest $request): array => $this->serializeRequest($request, $month))->values(),
            'preferredWorkRequests' => $preferredWorkRequests->map(fn (DoctorRequest $request): array => $this->serializeRequest($request, $month))->values(),
            'exclusions' => $exclusions->map(fn (DoctorMonthlyExclusion $exclusion): array => [
                'id' => $exclusion->id,
                'doctor' => [
                    'id' => $exclusion->doctor->id,
                    'name' => $exclusion->doctor->name,
                    'short_code' => $exclusion->doctor->short_code,
                    'is_active' => $exclusion->doctor->is_active,
                ],
                'note' => $exclusion->note,
            ])->values(),
            'summary' => [
                'active_doctors' => $activeDoctors->count(),
                'day_off_requests' => $dayOffRequests->count(),
                'preferred_work_requests' => $preferredWorkRequests->count(),
                'excluded_doctors' => $exclusions->count(),
            ],
            'warnings' => $warnings->values(),
        ];
    }

    /**
     * @param  Collection<int, DoctorRequest>  $dayOffRequests
     * @return Collection<int, array{type: string, message: string}>
     */
    private function lateWarnings(Collection $dayOffRequests, CarbonImmutable $month): Collection
    {
        $cutoff = $month->subMonth()->day(20)->endOfDay();

        return $dayOffRequests
            ->filter(fn (DoctorRequest $request): bool => $request->created_at !== null && $request->created_at->gt($cutoff))
            ->map(fn (DoctorRequest $request): array => [
                'type' => 'late_request',
                'message' => sprintf(
                    '%s\'s %s Day-Off request was entered after the normal %s cutoff.',
                    $request->doctor->name,
                    $request->request_date->format('M j'),
                    $cutoff->format('M j'),
                ),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, DoctorRequest>  $dayOffRequests
     * @return Collection<int, array{type: string, message: string}>
     */
    private function dayOffLimitWarnings(Collection $dayOffRequests): Collection
    {
        return $dayOffRequests
            ->groupBy('doctor_id')
            ->map(function (Collection $requests): ?array {
                $dateCount = $requests->pluck('request_date')->map->toDateString()->unique()->count();
                $firstRequest = $requests->first();

                return $dateCount > 3 && $firstRequest instanceof DoctorRequest
                    ? [
                        'type' => 'day_off_limit',
                        'message' => sprintf('%s has requested %d Day-Off dates this month.', $firstRequest->doctor->name, $dateCount),
                    ]
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, Doctor>  $activeDoctors
     * @param  Collection<int, DoctorMonthlyExclusion>  $exclusions
     * @param  Collection<int, ShiftType>  $shiftTypes
     * @return Collection<int, array{type: string, message: string}>
     */
    private function staffingRiskWarnings(
        CarbonImmutable $month,
        Collection $activeDoctors,
        Collection $exclusions,
        Collection $shiftTypes,
    ): Collection {
        $eligibleDoctorIds = $activeDoctors->pluck('id')->all();
        $excludedDoctorIds = $exclusions->pluck('doctor_id')->all();
        $eligibleDoctorIds = array_values(array_diff($eligibleDoctorIds, $excludedDoctorIds));
        $dayOffRequests = DoctorRequest::query()
            ->with('shiftType')
            ->where('request_type', DoctorRequestType::DayOff->value)
            ->whereIn('doctor_id', $eligibleDoctorIds)
            ->whereBetween('request_date', [$month->subDay(), $month->endOfMonth()->addDay()])
            ->get();
        $shiftTypesByCode = $shiftTypes->keyBy('code');
        $warnings = collect();

        for ($dayOffset = 0; $dayOffset < $month->daysInMonth; $dayOffset++) {
            $date = $month->addDays($dayOffset);
            $codes = $date->isWeekend()
                ? ['weekend_day', 'weekend_night']
                : ['weekday_day', 'weekday_evening', 'weekday_night'];

            foreach ($codes as $code) {
                $shiftType = $shiftTypesByCode->get($code);
                if (! $shiftType instanceof ShiftType) {
                    continue;
                }

                $shiftInterval = $this->intervals->forDate($date, $shiftType);
                $unavailableCount = $dayOffRequests
                    ->filter(fn (DoctorRequest $request): bool => $this->intervals->overlaps($shiftInterval, $this->intervals->forRequest($request)))
                    ->pluck('doctor_id')
                    ->unique()
                    ->count();
                $availableCount = count($eligibleDoctorIds) - $unavailableCount;
                $requiredCount = $shiftType->main_count + $shiftType->optional_count;

                if ($availableCount < $requiredCount) {
                    $warnings->push([
                        'type' => 'staffing_risk',
                        'message' => sprintf(
                            '%s %s may have insufficient availability: %d doctors available for %d required positions.',
                            $date->format('M j'),
                            $shiftType->name,
                            $availableCount,
                            $requiredCount,
                        ),
                    ]);
                }
            }
        }

        return $warnings;
    }

    /** @return array<string, mixed> */
    private function serializeRequest(DoctorRequest $request, CarbonImmutable $month): array
    {
        $interval = $this->intervals->forRequest($request);
        $cutoff = $month->subMonth()->day(20)->endOfDay();

        return [
            'id' => $request->id,
            'request_type' => $request->request_type->value,
            'request_date' => $request->request_date->toDateString(),
            'date_label' => $request->request_date->format('D, M j'),
            'doctor' => [
                'id' => $request->doctor->id,
                'name' => $request->doctor->name,
                'short_code' => $request->doctor->short_code,
                'is_active' => $request->doctor->is_active,
            ],
            'shift_type' => $request->shiftType === null ? null : [
                'id' => $request->shiftType->id,
                'code' => $request->shiftType->code,
                'name' => $request->shiftType->name,
            ],
            'period_label' => $request->shiftType === null
                ? 'Full Day'
                : sprintf(
                    '%s — %s → %s',
                    $request->shiftType->name,
                    $interval['start']->format('M j g:i A'),
                    $interval['end']->format('M j g:i A'),
                ),
            'note' => $request->note,
            'is_late' => $request->request_type === DoctorRequestType::DayOff
                && $request->created_at !== null
                && $request->created_at->gt($cutoff),
        ];
    }
}
