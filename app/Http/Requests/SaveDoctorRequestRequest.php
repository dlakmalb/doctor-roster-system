<?php

namespace App\Http\Requests;

use App\Enums\DoctorRequestType;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorRequest;
use App\Models\ShiftType;
use App\Services\RequestIntervalService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDoctorRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'],
            'request_type' => ['required', Rule::enum(DoctorRequestType::class)],
            'request_date' => ['required', 'date_format:Y-m-d'],
            'shift_type_id' => ['nullable', 'integer', 'exists:shift_types,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['doctor_id', 'request_type', 'request_date', 'shift_type_id'])) {
                return;
            }

            $this->validateRequestDetails($validator);
        }];
    }

    private function validateRequestDetails(Validator $validator): void
    {
        $doctorId = $this->integer('doctor_id');
        $requestType = DoctorRequestType::from($this->string('request_type')->toString());
        $requestDate = CarbonImmutable::createFromFormat('Y-m-d', $this->string('request_date')->toString())->startOfDay();
        $shiftTypeId = $this->filled('shift_type_id') ? $this->integer('shift_type_id') : null;
        $shiftType = $shiftTypeId === null ? null : ShiftType::find($shiftTypeId);
        $year = (int) $this->route('year');
        $month = (int) $this->route('month');
        $existingRequest = $this->route('doctorRequest');
        $existingRequest = $existingRequest instanceof DoctorRequest ? $existingRequest : null;

        if ($requestDate->year !== $year || $requestDate->month !== $month) {
            $validator->errors()->add('request_date', 'The request date must be within the selected month.');
        }

        $doctor = Doctor::find($doctorId);
        if (! $doctor?->is_active && ($existingRequest === null || $existingRequest->doctor_id !== $doctorId)) {
            $validator->errors()->add('doctor_id', 'Only active doctors can receive new requests.');
        }

        if ($requestType === DoctorRequestType::PreferredWork && $shiftType === null) {
            $validator->errors()->add('shift_type_id', 'Preferred Work requests require a specific shift.');
        }

        if ($shiftType !== null) {
            $validCodes = $requestDate->isWeekend()
                ? ['weekend_day', 'weekend_night']
                : ['weekday_day', 'weekday_evening', 'weekday_night'];

            if (! $shiftType->is_active || ! in_array($shiftType->code, $validCodes, true)) {
                $validator->errors()->add('shift_type_id', 'The selected shift is not valid for the request date.');
            }
        }

        if ($validator->errors()->hasAny(['request_date', 'doctor_id', 'shift_type_id'])) {
            return;
        }

        if (DoctorMonthlyExclusion::where('doctor_id', $doctorId)->where('year', $year)->where('month', $month)->exists()) {
            $validator->errors()->add('doctor_id', 'This doctor is excluded for the selected month.');
        }

        $duplicate = DoctorRequest::query()
            ->where('doctor_id', $doctorId)
            ->where('request_type', $requestType->value)
            ->whereDate('request_date', $requestDate)
            ->when(
                $shiftTypeId === null,
                fn (Builder $query): Builder => $query->whereNull('shift_type_id'),
                fn (Builder $query): Builder => $query->where('shift_type_id', $shiftTypeId),
            )
            ->when($existingRequest !== null, fn (Builder $query): Builder => $query->whereKeyNot($existingRequest?->id))
            ->exists();

        if ($duplicate) {
            $validator->errors()->add('request_date', 'An equivalent request already exists for this doctor.');
        }

        $oppositeType = $requestType === DoctorRequestType::DayOff
            ? DoctorRequestType::PreferredWork
            : DoctorRequestType::DayOff;
        $intervals = new RequestIntervalService;
        $requestedInterval = $intervals->forDate($requestDate, $shiftType);
        $possibleConflicts = DoctorRequest::query()
            ->with('shiftType')
            ->where('doctor_id', $doctorId)
            ->where('request_type', $oppositeType->value)
            ->whereBetween('request_date', [$requestDate->subDay(), $requestDate->addDay()])
            ->when($existingRequest !== null, fn (Builder $query): Builder => $query->whereKeyNot($existingRequest?->id))
            ->get();

        if ($possibleConflicts->contains(fn (DoctorRequest $candidate): bool => $intervals->overlaps(
            $requestedInterval,
            $intervals->forRequest($candidate),
        ))) {
            $validator->errors()->add('request_date', 'This request conflicts with an existing Day-Off or Preferred Work request.');
        }
    }
}
