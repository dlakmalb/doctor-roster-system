<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDoctorMonthlyExclusionRequest;
use App\Models\DoctorMonthlyExclusion;
use App\Models\User;
use App\Services\ActualWorkReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DoctorMonthlyExclusionController extends Controller
{
    public function store(StoreDoctorMonthlyExclusionRequest $request, int $year, int $month, ActualWorkReviewService $review): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $review->changeMonthlyExclusion($year, $month, $admin, function () use ($request, $year, $month, $admin): void {
            DoctorMonthlyExclusion::create([
                ...$request->safe()->only(['doctor_id', 'note']),
                'year' => $year,
                'month' => $month,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        });

        return to_route('monthly-setup.show', compact('year', 'month'));
    }

    public function destroy(int $year, int $month, DoctorMonthlyExclusion $doctorMonthlyExclusion, Request $request, ActualWorkReviewService $review): RedirectResponse
    {
        abort_unless(
            $doctorMonthlyExclusion->year === $year && $doctorMonthlyExclusion->month === $month,
            404,
        );

        /** @var User $admin */
        $admin = $request->user();
        $review->changeMonthlyExclusion($year, $month, $admin, function () use ($doctorMonthlyExclusion): void {
            $doctorMonthlyExclusion->delete();
        });

        return to_route('monthly-setup.show', compact('year', 'month'));
    }
}
