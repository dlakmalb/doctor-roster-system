<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDoctorRequestRequest;
use App\Models\DoctorRequest;
use Illuminate\Http\RedirectResponse;

class DoctorRequestController extends Controller
{
    public function store(SaveDoctorRequestRequest $request, int $year, int $month): RedirectResponse
    {
        DoctorRequest::create([
            ...$request->safe()->only(['doctor_id', 'request_type', 'request_date', 'shift_type_id', 'note']),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function update(SaveDoctorRequestRequest $request, int $year, int $month, DoctorRequest $doctorRequest): RedirectResponse
    {
        $this->ensureRequestBelongsToMonth($doctorRequest, $year, $month);

        $doctorRequest->update([
            ...$request->safe()->only(['doctor_id', 'request_type', 'request_date', 'shift_type_id', 'note']),
            'updated_by' => $request->user()?->id,
        ]);

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function destroy(int $year, int $month, DoctorRequest $doctorRequest): RedirectResponse
    {
        $this->ensureRequestBelongsToMonth($doctorRequest, $year, $month);
        $doctorRequest->delete();

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    private function ensureRequestBelongsToMonth(DoctorRequest $doctorRequest, int $year, int $month): void
    {
        abort_unless(
            $doctorRequest->request_date->year === $year && $doctorRequest->request_date->month === $month,
            404,
        );
    }
}
