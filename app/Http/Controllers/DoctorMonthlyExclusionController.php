<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDoctorMonthlyExclusionRequest;
use App\Models\DoctorMonthlyExclusion;
use Illuminate\Http\RedirectResponse;

class DoctorMonthlyExclusionController extends Controller
{
    public function store(StoreDoctorMonthlyExclusionRequest $request, int $year, int $month): RedirectResponse
    {
        DoctorMonthlyExclusion::create([
            ...$request->safe()->only(['doctor_id', 'note']),
            'year' => $year,
            'month' => $month,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function destroy(int $year, int $month, DoctorMonthlyExclusion $doctorMonthlyExclusion): RedirectResponse
    {
        abort_unless(
            $doctorMonthlyExclusion->year === $year && $doctorMonthlyExclusion->month === $month,
            404,
        );

        $doctorMonthlyExclusion->delete();

        return to_route('monthly-setup.show', compact('year', 'month'));
    }
}
