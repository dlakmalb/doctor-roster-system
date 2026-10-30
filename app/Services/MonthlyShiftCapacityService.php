<?php

namespace App\Services;

use App\Models\MonthlyShiftCapacity;
use App\Models\Roster;
use App\Models\ShiftType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyShiftCapacityService
{
    private const CONFIGURABLE_SHIFT_CODES = [
        'weekday_day',
        'weekday_evening',
        'weekday_night',
        'weekend_day',
        'weekend_night',
    ];

    /** @return array{main_count: int, optional_count: int} */
    public function resolve(int $year, int $month, ShiftType|string $shiftType): array
    {
        $this->validatePeriod($year, $month);
        $shiftTypeModel = $shiftType instanceof ShiftType
            ? $shiftType
            : ShiftType::query()->where('code', $shiftType)->first();

        if ($shiftTypeModel === null) {
            throw ValidationException::withMessages(['shift_type' => 'The selected shift type is invalid.']);
        }

        $customCapacity = MonthlyShiftCapacity::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where('shift_type_id', $shiftTypeModel->id)
            ->first();

        return [
            'main_count' => $customCapacity?->main_count ?? $shiftTypeModel->main_count,
            'optional_count' => $customCapacity?->optional_count ?? $shiftTypeModel->optional_count,
        ];
    }

    public function create(mixed $year, mixed $month, mixed $shiftTypeCode, mixed $mainCount, mixed $optionalCount): MonthlyShiftCapacity
    {
        return $this->save($year, $month, $shiftTypeCode, $mainCount, $optionalCount, false);
    }

    public function update(mixed $year, mixed $month, mixed $shiftTypeCode, mixed $mainCount, mixed $optionalCount): MonthlyShiftCapacity
    {
        return $this->save($year, $month, $shiftTypeCode, $mainCount, $optionalCount, true);
    }

    /**
     * @param  array<int, array{shift_type: mixed, main_count: mixed, optional_count: mixed}>  $capacities
     * @return array<int, MonthlyShiftCapacity>
     */
    public function saveAll(mixed $year, mixed $month, array $capacities): array
    {
        $this->validatePeriod($year, $month);
        $capacitiesByCode = [];
        $errors = [];

        foreach ($capacities as $index => $capacity) {
            if (! is_array($capacity)) {
                $errors["capacities.$index"] = 'Each shift capacity must be an object.';

                continue;
            }

            $code = $capacity['shift_type'] ?? null;
            $mainCount = $capacity['main_count'] ?? null;
            $optionalCount = $capacity['optional_count'] ?? null;

            if (! is_string($code) || ! in_array($code, self::CONFIGURABLE_SHIFT_CODES, true)) {
                $errors["capacities.$index.shift_type"] = 'The selected shift type is invalid.';

                continue;
            }

            if (array_key_exists($code, $capacitiesByCode)) {
                $errors["capacities.$index.shift_type"] = 'Each shift type may be configured only once.';
            }

            foreach (['main_count' => $mainCount, 'optional_count' => $optionalCount] as $field => $count) {
                if (! is_int($count) || $count < 0 || $count > 255) {
                    $errors["capacities.$index.$field"] = 'Capacity must be an integer from 0 through 255.';
                }
            }

            $capacitiesByCode[$code] = [
                'main_count' => $mainCount,
                'optional_count' => $optionalCount,
            ];
        }

        foreach (self::CONFIGURABLE_SHIFT_CODES as $code) {
            if (! array_key_exists($code, $capacitiesByCode)) {
                $errors['capacities'] = 'All five active shift types must be included.';
                break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($year, $month, $capacitiesByCode): array {
            $shiftTypes = ShiftType::query()
                ->whereIn('code', self::CONFIGURABLE_SHIFT_CODES)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('code');

            $unavailableCodes = collect(self::CONFIGURABLE_SHIFT_CODES)
                ->filter(fn (string $code): bool => ! $shiftTypes->has($code) || ! $shiftTypes->get($code)->is_active);

            if ($unavailableCodes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'capacities' => 'Capacity can be saved only when all five shift types are active.',
                ]);
            }

            if (Roster::query()->where('year', $year)->where('month', $month)->exists()) {
                throw ValidationException::withMessages([
                    'month' => 'Shift capacities cannot change after a roster record has been created.',
                ]);
            }

            $existing = MonthlyShiftCapacity::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->get()
                ->keyBy('shift_type_id');

            $saved = [];
            foreach ($capacitiesByCode as $code => $values) {
                /** @var ShiftType $shiftType */
                $shiftType = $shiftTypes->get($code);
                $configuration = $existing->get($shiftType->id)
                    ?? new MonthlyShiftCapacity([
                        'year' => $year,
                        'month' => $month,
                        'shift_type_id' => $shiftType->id,
                    ]);
                $configuration->fill($values);
                $configuration->save();
                $saved[] = $configuration;
            }

            return $saved;
        });
    }

    private function save(mixed $year, mixed $month, mixed $shiftTypeCode, mixed $mainCount, mixed $optionalCount, bool $update): MonthlyShiftCapacity
    {
        $this->validateValues($year, $month, $shiftTypeCode, $mainCount, $optionalCount);

        return DB::transaction(function () use ($year, $month, $shiftTypeCode, $mainCount, $optionalCount, $update): MonthlyShiftCapacity {
            $shiftType = ShiftType::query()
                ->where('code', $shiftTypeCode)
                ->lockForUpdate()
                ->first();

            if ($shiftType === null) {
                throw ValidationException::withMessages(['shift_type' => 'The selected shift type is invalid.']);
            }

            if (Roster::query()->where('year', $year)->where('month', $month)->exists()) {
                throw ValidationException::withMessages([
                    'month' => 'Monthly shift capacities cannot change after the roster structure has been created.',
                ]);
            }

            $configuration = MonthlyShiftCapacity::query()
                ->where('year', $year)
                ->where('month', $month)
                ->where('shift_type_id', $shiftType->id)
                ->lockForUpdate()
                ->first();

            if ($update && $configuration === null) {
                throw ValidationException::withMessages(['shift_type' => 'No monthly capacity configuration exists to update.']);
            }

            if (! $update && $configuration !== null) {
                throw ValidationException::withMessages(['shift_type' => 'A monthly capacity configuration already exists for this shift type.']);
            }

            $configuration ??= new MonthlyShiftCapacity([
                'year' => $year,
                'month' => $month,
                'shift_type_id' => $shiftType->id,
            ]);
            $configuration->fill(['main_count' => $mainCount, 'optional_count' => $optionalCount]);
            $configuration->save();

            return $configuration;
        });
    }

    private function validateValues(mixed $year, mixed $month, mixed $shiftTypeCode, mixed $mainCount, mixed $optionalCount): void
    {
        $this->validatePeriod($year, $month);
        if (! is_string($shiftTypeCode) || trim($shiftTypeCode) === '') {
            throw ValidationException::withMessages(['shift_type' => 'A shift type is required.']);
        }
        foreach (['main_count' => $mainCount, 'optional_count' => $optionalCount] as $field => $count) {
            if (! is_int($count) || $count < 0 || $count > 255) {
                throw ValidationException::withMessages([$field => 'Capacity must be an integer from 0 through 255.']);
            }
        }
    }

    private function validatePeriod(mixed $year, mixed $month): void
    {
        if (! is_int($year) || $year < 1 || $year > 65535) {
            throw ValidationException::withMessages(['year' => 'Year must be from 1 through 65535.']);
        }
        if (! is_int($month) || $month < 1 || $month > 12) {
            throw ValidationException::withMessages(['month' => 'Month must be from 1 through 12.']);
        }
    }
}
