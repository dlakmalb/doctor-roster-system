<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\ShiftType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DoctorMonthlyShiftRestrictionController extends Controller
{
    public function store(Request $request, int $year, int $month): RedirectResponse
    {
        $validated = $this->validateInput($request, true);
        $this->sync($year, $month, $validated['doctor_id'], $validated['shift_type_ids']);

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function update(Request $request, int $year, int $month, DoctorMonthlyShiftRestriction $doctorMonthlyShiftRestriction): RedirectResponse
    {
        abort_unless($doctorMonthlyShiftRestriction->year === $year && $doctorMonthlyShiftRestriction->month === $month, 404);
        $validated = $this->validateInput($request, false);
        abort_unless($validated['doctor_id'] === $doctorMonthlyShiftRestriction->doctor_id, 404);
        $existingShiftTypeIds = DoctorMonthlyShiftRestriction::query()
            ->where('doctor_id', $validated['doctor_id'])->where('year', $year)->where('month', $month)
            ->pluck('shift_type_id')->all();
        if (array_diff($validated['shift_type_ids'], $existingShiftTypeIds) !== []
            && ! Doctor::query()->whereKey($validated['doctor_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['doctor_id' => 'New restrictions can only be added for active doctors.']);
        }
        $this->sync($year, $month, $validated['doctor_id'], $validated['shift_type_ids']);

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function destroy(int $year, int $month, DoctorMonthlyShiftRestriction $doctorMonthlyShiftRestriction): RedirectResponse
    {
        abort_unless($doctorMonthlyShiftRestriction->year === $year && $doctorMonthlyShiftRestriction->month === $month, 404);
        $doctorMonthlyShiftRestriction->delete();

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    /** @return array{doctor_id: int, shift_type_ids: list<int>} */
    private function validateInput(Request $request, bool $requireActiveDoctor): array
    {
        $doctorRule = Rule::exists('doctors', 'id');
        if ($requireActiveDoctor) {
            $doctorRule->where('is_active', true);
        }

        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', $doctorRule],
            'shift_type_ids' => ['present', 'array'],
            'shift_type_ids.*' => ['required', 'integer', 'distinct', Rule::exists('shift_types', 'id')->where('is_active', true)],
        ]);
        $validIds = ShiftType::query()->where('is_active', true)
            ->whereIn('id', $validated['shift_type_ids'])->whereIn('code', ['weekday_day', 'weekday_evening', 'weekday_night', 'weekend_day', 'weekend_night'])
            ->pluck('id')->all();
        if (count($validIds) !== count($validated['shift_type_ids'])) {
            throw ValidationException::withMessages(['shift_type_ids' => 'Select only active roster shift types.']);
        }

        return ['doctor_id' => (int) $validated['doctor_id'], 'shift_type_ids' => array_values(array_map('intval', $validated['shift_type_ids']))];
    }

    /** @param list<int> $shiftTypeIds */
    private function sync(int $year, int $month, int $doctorId, array $shiftTypeIds): void
    {
        DB::transaction(function () use ($year, $month, $doctorId, $shiftTypeIds): void {
            $existing = DoctorMonthlyShiftRestriction::query()->where('doctor_id', $doctorId)->where('year', $year)->where('month', $month)->get();
            $existingIds = $existing->pluck('shift_type_id')->all();
            DoctorMonthlyShiftRestriction::query()->where('doctor_id', $doctorId)->where('year', $year)->where('month', $month)
                ->whereNotIn('shift_type_id', $shiftTypeIds)->delete();

            foreach (array_diff($shiftTypeIds, $existingIds) as $shiftTypeId) {
                DoctorMonthlyShiftRestriction::create(['doctor_id' => $doctorId, 'year' => $year, 'month' => $month, 'shift_type_id' => $shiftTypeId]);
            }
        });
    }
}
