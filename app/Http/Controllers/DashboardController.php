<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use App\Services\RosterHistoryReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(RosterHistoryReadinessService $history): Response
    {
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $months = collect([$currentMonth, $currentMonth->addMonth()]);
        $rosters = Roster::query()
            ->where(function (Builder $query) use ($months): void {
                foreach ($months as $month) {
                    $query->orWhere(fn (Builder $monthQuery): Builder => $monthQuery
                        ->where('year', $month->year)
                        ->where('month', $month->month));
                }
            })
            ->get()
            ->keyBy(fn (Roster $roster): string => $roster->year.'-'.$roster->month);
        $monthSummaries = [];

        foreach ($months as $month) {
            $roster = $rosters->get($month->year.'-'.$month->month);
            $monthSummaries[] = [
                'year' => $month->year,
                'month' => $month->month,
                'label' => $month->format('F Y'),
                'status' => $roster?->status->value ?? 'not_started',
                'history_readiness' => $history->forMonth($month->year, $month->month),
                'actual_work_confirmed' => $roster?->actual_work_confirmed_at !== null,
            ];
        }

        return Inertia::render('dashboard', [
            'months' => $monthSummaries,
        ]);
    }
}
