<?php

namespace App\Services;

use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\Roster;
use App\Models\ShiftType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterStructureService
{
    private const WEEKDAY_CODES = ['weekday_day', 'weekday_evening', 'weekday_night'];

    private const WEEKEND_CODES = ['weekend_day', 'weekend_night'];

    public function __construct(private DoctorMonthlyParticipationService $participation) {}

    public function create(int $year, int $month, User $creator): Roster
    {
        return DB::transaction(function () use ($year, $month, $creator): Roster {
            $existing = Roster::query()->where('year', $year)->where('month', $month)->first();

            if ($existing !== null) {
                return $existing;
            }

            $requiredCodes = [...self::WEEKDAY_CODES, ...self::WEEKEND_CODES];
            $shiftTypes = ShiftType::query()->whereIn('code', $requiredCodes)->get()->keyBy('code');
            $unavailableCodes = collect($requiredCodes)
                ->filter(function (string $code) use ($shiftTypes): bool {
                    $shiftType = $shiftTypes->get($code);

                    return $shiftType === null || ! $shiftType->is_active;
                });

            if ($unavailableCodes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'roster' => 'Cannot create the roster. Required shift types are missing or inactive: '.$unavailableCodes->implode(', ').'.',
                ]);
            }

            $roster = Roster::create([
                'year' => $year,
                'month' => $month,
                'status' => RosterStatus::Draft,
                'created_by' => $creator->id,
                'updated_by' => $creator->id,
            ]);

            $this->participation->snapshotRoster(
                $roster,
                Doctor::query()->where('is_active', true)->get(),
            );

            $date = CarbonImmutable::create($year, $month, 1)->startOfDay();
            $lastDate = $date->endOfMonth();

            while ($date->lessThanOrEqualTo($lastDate)) {
                $codes = $date->isWeekend() ? self::WEEKEND_CODES : self::WEEKDAY_CODES;

                foreach ($codes as $code) {
                    /** @var ShiftType $shiftType */
                    $shiftType = $shiftTypes->get($code);
                    $roster->shifts()->create([
                        'shift_type_id' => $shiftType->id,
                        'shift_date' => $date->toDateString(),
                    ]);
                }

                $date = $date->addDay();
            }

            return $roster;
        });
    }
}
