<?php

namespace App\Http\Requests;

use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDoctorMonthlyExclusionRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('doctor_id')) {
                return;
            }

            $doctorId = $this->integer('doctor_id');
            $year = (int) $this->route('year');
            $month = (int) $this->route('month');
            $doctor = Doctor::find($doctorId);

            if (! $doctor?->is_active) {
                $validator->errors()->add('doctor_id', 'Only active doctors can be excluded.');
            }

            $participation = DoctorMonthlyParticipation::query()
                ->where('doctor_id', $doctorId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();
            if ($participation !== null && ! $participation->is_participating) {
                $validator->errors()->add('doctor_id', 'A doctor who is not part of the team cannot be fully excluded.');
            }

            if (DoctorMonthlyExclusion::where('doctor_id', $doctorId)->where('year', $year)->where('month', $month)->exists()) {
                $validator->errors()->add('doctor_id', 'This doctor is already excluded for the selected month.');
            }

            $start = CarbonImmutable::create($year, $month, 1)->startOfMonth();
            if (DoctorRequest::where('doctor_id', $doctorId)->whereBetween('request_date', [$start, $start->endOfMonth()])->exists()) {
                $validator->errors()->add('doctor_id', 'Remove this doctor\'s existing requests for the month before excluding them.');
            }
        }];
    }
}
