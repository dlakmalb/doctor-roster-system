<?php

namespace App\Http\Middleware;

use App\Enums\RosterStatus;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorRequest;
use App\Models\Roster;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureMonthlySetupEditable
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $year = (int) $request->route('year');
        $month = (int) $request->route('month');
        $this->ensureResourcesBelongToMonth($request, $year, $month);

        return DB::transaction(function () use ($next, $request, $year, $month): Response|RedirectResponse {
            $roster = Roster::query()->where('year', $year)->where('month', $month)->lockForUpdate()->first();
            if ($roster?->status !== RosterStatus::Final) {
                return $next($request);
            }

            $label = CarbonImmutable::create($year, $month, 1)->format('F Y');

            return to_route('monthly-setup.show', compact('year', 'month'))
                ->with('status', "Monthly Setup is locked because the {$label} roster is Final. Reopen the roster before making changes.");
        });
    }

    private function ensureResourcesBelongToMonth(Request $request, int $year, int $month): void
    {
        $doctorRequest = $request->route('doctorRequest');
        if ($doctorRequest instanceof DoctorRequest) {
            abort_unless(
                $doctorRequest->request_date->year === $year && $doctorRequest->request_date->month === $month,
                404,
            );
        }

        $exclusion = $request->route('doctorMonthlyExclusion');
        if ($exclusion instanceof DoctorMonthlyExclusion) {
            abort_unless($exclusion->year === $year && $exclusion->month === $month, 404);
        }

        $restriction = $request->route('doctorMonthlyShiftRestriction');
        if ($restriction instanceof DoctorMonthlyShiftRestriction) {
            abort_unless($restriction->year === $year && $restriction->month === $month, 404);
        }
    }
}
