<?php

namespace App\Services;

use App\Models\MonthlyShiftCapacity;
use App\Models\Roster;
use App\Models\ShiftType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyShiftCapacityService
{
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
