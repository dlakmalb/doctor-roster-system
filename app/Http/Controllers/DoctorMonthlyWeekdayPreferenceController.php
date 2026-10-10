<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\ShiftType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DoctorMonthlyWeekdayPreferenceController extends Controller
{
    public function store(Request $request, int $year, int $month): RedirectResponse
    {
        $validated = $this->validateInput($request);
        $shiftType = ShiftType::query()->findOrFail($validated['shift_type_id']);
        foreach ($validated['weekdays'] as $weekday) {
            $this->ensureCompatible($shiftType->code, $weekday, 'weekdays');
        }

        $duplicate = DoctorMonthlyWeekdayPreference::query()
            ->where('doctor_id', $validated['doctor_id'])->where('year', $year)->where('month', $month)
            ->where('shift_type_id', $validated['shift_type_id'])->whereIn('weekday', $validated['weekdays'])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['weekdays' => 'One or more selected weekday preferences already exist.']);
        }

        DB::transaction(function () use ($validated, $year, $month): void {
            foreach ($validated['weekdays'] as $weekday) {
                DoctorMonthlyWeekdayPreference::create([
                    'doctor_id' => $validated['doctor_id'],
                    'year' => $year,
                    'month' => $month,
                    'shift_type_id' => $validated['shift_type_id'],
                    'weekday' => $weekday,
                ]);
            }
        });

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function update(Request $request, int $year, int $month, DoctorMonthlyWeekdayPreference $doctorMonthlyWeekdayPreference): RedirectResponse
    {
        abort_unless($doctorMonthlyWeekdayPreference->year === $year && $doctorMonthlyWeekdayPreference->month === $month, 404);
        abort_unless(Doctor::query()->whereKey($doctorMonthlyWeekdayPreference->doctor_id)->where('is_active', true)->exists(), 404);

        $validated = $this->validateUpdateInput($request, $year, $month, $doctorMonthlyWeekdayPreference);
        $shiftType = ShiftType::query()->findOrFail($validated['shift_type_id']);
        $weekday = $validated['weekdays'][0];
        $this->ensureCompatible($shiftType->code, $weekday, 'weekdays');
        $doctorMonthlyWeekdayPreference->update([
            'doctor_id' => $validated['doctor_id'],
            'shift_type_id' => $validated['shift_type_id'],
            'weekday' => $weekday,
        ]);

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function destroy(int $year, int $month, DoctorMonthlyWeekdayPreference $doctorMonthlyWeekdayPreference): RedirectResponse
    {
        abort_unless($doctorMonthlyWeekdayPreference->year === $year && $doctorMonthlyWeekdayPreference->month === $month, 404);
        $doctorMonthlyWeekdayPreference->delete();

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    /** @return array{doctor_id: int, shift_type_id: int, weekdays: list<int>} */
    private function validateInput(Request $request): array
    {
        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('is_active', true)],
            'shift_type_id' => ['required', 'integer', Rule::exists('shift_types', 'id')->where('is_active', true)],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['required', 'integer', 'distinct', 'between:1,7'],
        ]);

        $shiftType = ShiftType::query()->whereKey($validated['shift_type_id'])
            ->where('is_active', true)
            ->whereIn('code', ['weekday_day', 'weekday_evening', 'weekday_night', 'weekend_day', 'weekend_night'])
            ->first();
        if ($shiftType === null) {
            throw ValidationException::withMessages(['shift_type_id' => 'Select an active roster shift type.']);
        }

        return [
            'doctor_id' => (int) $validated['doctor_id'],
            'shift_type_id' => (int) $validated['shift_type_id'],
            'weekdays' => array_values(array_map('intval', $validated['weekdays'])),
        ];
    }

    /** @return array{doctor_id: int, shift_type_id: int, weekdays: list<int>} */
    private function validateUpdateInput(Request $request, int $year, int $month, DoctorMonthlyWeekdayPreference $preference): array
    {
        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('is_active', true)],
            'shift_type_id' => ['required', 'integer', Rule::exists('shift_types', 'id')->where('is_active', true)],
            'weekdays' => ['required', 'array', 'size:1'],
            'weekdays.*' => ['required', 'integer', 'distinct', 'between:1,7'],
        ]);
        $shiftType = ShiftType::query()->whereKey($validated['shift_type_id'])
            ->where('is_active', true)
            ->whereIn('code', ['weekday_day', 'weekday_evening', 'weekday_night', 'weekend_day', 'weekend_night'])
            ->first();
        if ($shiftType === null) {
            throw ValidationException::withMessages(['shift_type_id' => 'Select an active roster shift type.']);
        }

        $duplicate = DoctorMonthlyWeekdayPreference::query()
            ->where('doctor_id', $validated['doctor_id'])->where('year', $year)->where('month', $month)
            ->where('shift_type_id', $validated['shift_type_id'])->where('weekday', $validated['weekdays'][0])
            ->where('id', '!=', $preference->id)->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['weekdays' => 'This weekday preference already exists.']);
        }

        return [
            'doctor_id' => (int) $validated['doctor_id'],
            'shift_type_id' => (int) $validated['shift_type_id'],
            'weekdays' => array_values(array_map('intval', $validated['weekdays'])),
        ];
    }

    private function ensureCompatible(string $shiftTypeCode, int $weekday, string $errorKey): void
    {
        $weekdayCode = in_array($weekday, [6, 7], true);
        $weekendCode = str_starts_with($shiftTypeCode, 'weekend_');
        if ($weekdayCode !== $weekendCode) {
            throw ValidationException::withMessages([$errorKey => 'Choose a weekday compatible with this shift type.']);
        }
    }
}
