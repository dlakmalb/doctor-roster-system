<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\MonthlyShiftCapacity;
use App\Models\Roster;
use App\Models\RosterShift;
use App\Models\RosterShiftCapacitySnapshot;
use App\Models\ShiftType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MonthlySetupService
{
    private const CAPACITY_DISPLAY_ORDER = [
        'weekday_day',
        'weekday_evening',
        'weekday_night',
        'weekend_day',
        'weekend_night',
    ];

    public function __construct(
        private RequestIntervalService $intervals,
        private MonthlyShiftCapacityService $capacities,
        private WeekendDayCapacityService $weekendCapacities,
    ) {}

    /** @return array<string, mixed> */
    public function build(int $year, int $monthNumber, bool $isPlanningPeriod = true): array
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
        $shiftRestrictions = DoctorMonthlyShiftRestriction::query()
            ->with(['doctor:id,name,short_code,is_active', 'shiftType:id,code,name'])
            ->where('year', $year)->where('month', $monthNumber)
            ->orderBy('doctor_id')->orderBy('shift_type_id')->get();
        $weekdayPreferences = DoctorMonthlyWeekdayPreference::query()
            ->with(['doctor:id,name,short_code,is_active', 'shiftType:id,code,name,is_active'])
            ->where('year', $year)->where('month', $monthNumber)
            ->orderBy('doctor_id')->orderBy('shift_type_id')->orderBy('weekday')->get();
        $shiftTypes = ShiftType::query()
            ->where('is_active', true)
            ->whereIn('code', ['weekday_day', 'weekday_evening', 'weekday_night', 'weekend_day', 'weekend_night'])
            ->orderBy('start_time')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'start_time', 'end_time', 'main_count', 'optional_count', 'is_overnight']);
        $roster = Roster::query()->where('year', $year)->where('month', $monthNumber)->first();
        $rosterShiftsByDateAndType = $roster === null
            ? collect()
            : $roster->shifts()
                ->with('capacitySnapshot')
                ->get()
                ->keyBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString().'|'.$shift->shift_type_id);

        $weekendDayCapacities = [];
        $weekendCapacityError = null;
        if ($roster === null && $shiftTypes->contains('code', 'weekend_day')) {
            try {
                $legacy = $this->capacities->resolve($year, $monthNumber, 'weekend_day');
                $weekendDayCapacities = $this->weekendCapacities->forMonth($year, $monthNumber, $legacy['main_count']);
            } catch (ValidationException $exception) {
                $weekendCapacityError = implode(' ', array_merge(...array_values($exception->errors())));
            }
        }

        $dayOffRequests = $requests->where('request_type', DoctorRequestType::DayOff);
        $preferredWorkRequests = $requests->where('request_type', DoctorRequestType::PreferredWork);
        $warnings = collect()
            ->concat($this->lateWarnings($dayOffRequests, $month))
            ->concat($this->dayOffLimitWarnings($dayOffRequests))
            ->concat($this->staffingRiskWarnings($month, $activeDoctors, $exclusions, $shiftTypes, $shiftRestrictions, $roster, $rosterShiftsByDateAndType, $weekendDayCapacities, $weekendCapacityError));
        foreach ($requests->where('request_type', DoctorRequestType::PreferredWork) as $preferredRequest) {
            if ($shiftRestrictions->contains(fn (DoctorMonthlyShiftRestriction $restriction): bool => $restriction->doctor_id === $preferredRequest->doctor_id && $restriction->shift_type_id === $preferredRequest->shift_type_id)) {
                $warnings->push(['type' => 'restricted_preferred_work', 'message' => "{$preferredRequest->doctor->name}'s Preferred Work request conflicts with a Monthly Shift Restriction."]);
            }
        }
        foreach ($weekdayPreferences as $preference) {
            if ($shiftRestrictions->contains(fn (DoctorMonthlyShiftRestriction $restriction): bool => $restriction->doctor_id === $preference->doctor_id && $restriction->shift_type_id === $preference->shift_type_id)) {
                $warnings->push(['type' => 'restricted_weekday_preference', 'message' => "{$preference->doctor->name}'s {$preference->shiftType->name} weekday preference conflicts with a Monthly Shift Restriction and will not affect ranking."]);
            }
        }
        $fourthAndLaterDatesByDoctor = $dayOffRequests
            ->groupBy('doctor_id')
            ->map(fn (Collection $requests): array => $requests
                ->pluck('request_date')
                ->map(fn (CarbonImmutable $date): string => $date->toDateString())
                ->unique()
                ->sort()
                ->values()
                ->slice(3)
                ->all());
        $unifiedRequests = $dayOffRequests
            ->map(fn (DoctorRequest $request): array => [
                ...$this->serializeRequest($request, $month),
                'kind' => 'off_request',
                'has_date_limit_warning' => in_array(
                    $request->request_date->toDateString(),
                    $fourthAndLaterDatesByDoctor->get($request->doctor_id, []),
                    true,
                ),
            ])
            ->concat($preferredWorkRequests->map(fn (DoctorRequest $request): array => [
                ...$this->serializeRequest($request, $month),
                'kind' => 'preferred_work',
                'has_date_limit_warning' => false,
            ]))
            ->concat($exclusions->map(fn (DoctorMonthlyExclusion $exclusion): array => [
                'id' => $exclusion->id,
                'kind' => 'monthly_exclusion',
                'request_date' => null,
                'date_label' => $month->format('F Y'),
                'doctor' => [
                    'id' => $exclusion->doctor->id,
                    'name' => $exclusion->doctor->name,
                    'short_code' => $exclusion->doctor->short_code,
                    'is_active' => $exclusion->doctor->is_active,
                ],
                'shift_type' => null,
                'shift_label' => null,
                'note' => $exclusion->note,
                'is_late' => false,
                'has_date_limit_warning' => false,
            ]))
            ->sortBy(fn (array $request): string => ($request['request_date'] ?? $month->toDateString()).'-'.$request['doctor']['name'])
            ->values();

        $monthlyCapacities = MonthlyShiftCapacity::query()
            ->where('year', $year)
            ->where('month', $monthNumber)
            ->get()
            ->keyBy('shift_type_id');
        $capacityShiftTypes = $roster === null
            ? $shiftTypes
            : ShiftType::query()
                ->whereIn('code', ['weekday_day', 'weekday_evening', 'weekday_night', 'weekend_day', 'weekend_night'])
                ->orderBy('start_time')
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'start_time', 'end_time', 'main_count', 'optional_count', 'is_overnight']);
        $rosterCapacities = $roster === null
            ? collect()
            : $rosterShiftsByDateAndType->groupBy('shift_type_id');
        $capacityOrder = array_flip(self::CAPACITY_DISPLAY_ORDER);
        $capacityConfiguration = $capacityShiftTypes->map(function (ShiftType $shiftType) use ($year, $monthNumber, $roster, $monthlyCapacities, $rosterCapacities): array {
            if ($roster !== null) {
                /** @var Collection<int, RosterShift> $shifts */
                $shifts = $rosterCapacities->get($shiftType->id, collect());
                $snapshots = $shifts->map(fn (RosterShift $shift) => $shift->capacitySnapshot);
                $complete = $shifts->isNotEmpty() && $snapshots->every(fn (?RosterShiftCapacitySnapshot $snapshot): bool => $snapshot !== null);
                $uniqueCapacities = $snapshots
                    ->filter()
                    ->map(fn (RosterShiftCapacitySnapshot $snapshot): string => $snapshot->main_count.':'.$snapshot->optional_count)
                    ->unique();

                $validWeekendVariation = $complete && $shiftType->code === 'weekend_day'
                    && $snapshots->pluck('optional_count')->unique()->count() === 1
                    && $shifts->groupBy(fn (RosterShift $shift): string => ($shift->shift_date->isSunday() ? $shift->shift_date->copy()->subDay() : $shift->shift_date)->toDateString())
                        ->every(fn (Collection $weekend): bool => $weekend->map(fn (RosterShift $shift): int => $shift->savedCapacity()->main_count)->unique()->count() === 1);

                if ($validWeekendVariation && $uniqueCapacities->count() > 1) {
                    return [
                        'shift_type' => ['id' => $shiftType->id, 'code' => $shiftType->code, 'name' => $shiftType->name],
                        'main_count' => null,
                        'optional_count' => $snapshots->first()->optional_count,
                        'source' => 'roster_snapshot',
                        'error' => null,
                        'per_shift' => $shifts->map(fn (RosterShift $shift): array => [
                            'date' => $shift->shift_date->toDateString(),
                            'main_count' => $shift->savedCapacity()->main_count,
                            'optional_count' => $shift->savedCapacity()->optional_count,
                        ])->values()->all(),
                    ];
                }

                if (! $complete || $uniqueCapacities->count() !== 1) {
                    return [
                        'shift_type' => ['id' => $shiftType->id, 'code' => $shiftType->code, 'name' => $shiftType->name],
                        'main_count' => null,
                        'optional_count' => null,
                        'source' => 'inconsistent',
                        'error' => 'Saved roster capacity snapshots are missing or inconsistent for this shift type.',
                    ];
                }

                [$mainCount, $optionalCount] = explode(':', $uniqueCapacities->first());

                return [
                    'shift_type' => ['id' => $shiftType->id, 'code' => $shiftType->code, 'name' => $shiftType->name],
                    'main_count' => (int) $mainCount,
                    'optional_count' => (int) $optionalCount,
                    'source' => 'roster_snapshot',
                    'error' => null,
                ];
            }

            $resolved = $this->capacities->resolve($year, $monthNumber, $shiftType);

            return [
                'shift_type' => ['id' => $shiftType->id, 'code' => $shiftType->code, 'name' => $shiftType->name],
                'main_count' => $resolved['main_count'],
                'optional_count' => $resolved['optional_count'],
                'source' => $monthlyCapacities->has($shiftType->id) ? 'monthly_custom' : 'global_default',
                'error' => null,
            ];
        })->sortBy(fn (array $capacity): int => $capacityOrder[$capacity['shift_type']['code']])->values();

        return [
            'month' => [
                'year' => $year,
                'month' => $monthNumber,
                'label' => $month->format('F Y'),
            ],
            'rosterStatus' => $roster?->status->value ?? 'not_started',
            'rosterAction' => match (true) {
                $roster === null => 'generate',
                $roster->status->value === 'final' => 'view_final',
                ! $isPlanningPeriod => 'view_draft',
                $roster->last_generated_at !== null => 'view_draft',
                default => 'generate',
            },
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
            'capacityConfiguration' => $capacityConfiguration,
            'capacityEditable' => $roster === null,
            'weekendDayCapacities' => array_values($weekendDayCapacities),
            'weekendCapacityError' => $weekendCapacityError,
            'shiftRestrictions' => $shiftRestrictions->map(fn (DoctorMonthlyShiftRestriction $restriction): array => [
                'id' => $restriction->id,
                'doctor' => ['id' => $restriction->doctor->id, 'name' => $restriction->doctor->name, 'short_code' => $restriction->doctor->short_code, 'is_active' => $restriction->doctor->is_active],
                'shift_type' => ['id' => $restriction->shiftType->id, 'code' => $restriction->shiftType->code, 'name' => $restriction->shiftType->name],
            ])->values(),
            'weekdayPreferences' => $weekdayPreferences->map(fn (DoctorMonthlyWeekdayPreference $preference): array => [
                'id' => $preference->id,
                'doctor' => ['id' => $preference->doctor->id, 'name' => $preference->doctor->name, 'short_code' => $preference->doctor->short_code, 'is_active' => $preference->doctor->is_active],
                'shift_type' => ['id' => $preference->shiftType->id, 'code' => $preference->shiftType->code, 'name' => $preference->shiftType->name],
                'weekday' => $preference->weekday,
                'weekday_name' => CarbonImmutable::create(2024, 1, $preference->weekday)->format('l'),
                'conflicts_with_restriction' => $shiftRestrictions->contains(fn (DoctorMonthlyShiftRestriction $restriction): bool => $restriction->doctor_id === $preference->doctor_id && $restriction->shift_type_id === $preference->shift_type_id),
            ])->values(),
            'dayOffRequests' => $dayOffRequests->map(fn (DoctorRequest $request): array => $this->serializeRequest($request, $month))->values(),
            'preferredWorkRequests' => $preferredWorkRequests->map(fn (DoctorRequest $request): array => $this->serializeRequest($request, $month))->values(),
            'requests' => $unifiedRequests,
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
                'total_requests' => $unifiedRequests->count(),
                'warnings' => $warnings->count(),
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
                    '%s\'s %s Off Request was entered after the normal %s cutoff.',
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
                        'message' => sprintf('%s has requested %d Off Request dates this month.', $firstRequest->doctor->name, $dateCount),
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
     * @param  Collection<int, DoctorMonthlyShiftRestriction>  $shiftRestrictions
     * @param  Collection<string, RosterShift>  $rosterShiftsByDateAndType
     * @param  array<string, array{main_count: int}>  $weekendDayCapacities
     * @return Collection<int, array{type: string, message: string}>
     */
    private function staffingRiskWarnings(
        CarbonImmutable $month,
        Collection $activeDoctors,
        Collection $exclusions,
        Collection $shiftTypes,
        Collection $shiftRestrictions,
        ?Roster $roster,
        Collection $rosterShiftsByDateAndType,
        array $weekendDayCapacities,
        ?string $weekendCapacityError,
    ): Collection {
        $eligibleDoctorIds = $activeDoctors->pluck('id')->all();
        $excludedDoctorIds = $exclusions->pluck('doctor_id')->all();
        $eligibleDoctorIds = array_values(array_diff($eligibleDoctorIds, $excludedDoctorIds));
        $restrictedByDoctor = $shiftRestrictions->groupBy('shift_type_id')->map(fn (Collection $rows): array => $rows->pluck('doctor_id')->all());
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
                $unavailableDoctorIds = $dayOffRequests
                    ->filter(fn (DoctorRequest $request): bool => $this->intervals->overlaps($shiftInterval, $this->intervals->forRequest($request)))
                    ->pluck('doctor_id')
                    ->unique()
                    ->all();
                $unavailableDoctorIds = array_unique([...$unavailableDoctorIds, ...array_intersect($eligibleDoctorIds, $restrictedByDoctor->get($shiftType->id, []))]);
                $availableCount = count($eligibleDoctorIds) - count($unavailableDoctorIds);
                if ($roster !== null) {
                    $rosterShift = $rosterShiftsByDateAndType->get($date->toDateString().'|'.$shiftType->id);
                    $snapshot = $rosterShift instanceof RosterShift ? $rosterShift->capacitySnapshot : null;

                    if (! $snapshot instanceof RosterShiftCapacitySnapshot) {
                        continue;
                    }

                    $requiredCount = $snapshot->main_count + $snapshot->optional_count;
                } else {
                    $capacity = $this->capacities->resolve($month->year, $month->month, $shiftType);
                    if ($code === 'weekend_day') {
                        if ($weekendCapacityError !== null) {
                            continue;
                        }
                        $saturday = $date->isSunday() ? $date->subDay() : $date;
                        $capacity['main_count'] = $weekendDayCapacities[$saturday->toDateString()]['main_count'];
                    }
                    $requiredCount = $capacity['main_count'] + $capacity['optional_count'];
                }

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
                    '%s - %s → %s',
                    $request->shiftType->name,
                    $interval['start']->format('M j g:i A'),
                    $interval['end']->format('M j g:i A'),
                ),
            'shift_label' => $request->shiftType === null
                ? 'Full Day'
                : match (true) {
                    str_ends_with($request->shiftType->code, '_day') => 'Day',
                    str_ends_with($request->shiftType->code, '_evening') => 'Evening',
                    str_ends_with($request->shiftType->code, '_night') => 'Night',
                    default => $request->shiftType->name,
                },
            'note' => $request->note,
            'is_late' => $request->request_type === DoctorRequestType::DayOff
                && $request->created_at !== null
                && $request->created_at->gt($cutoff),
        ];
    }
}
