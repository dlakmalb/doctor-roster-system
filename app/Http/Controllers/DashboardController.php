<?php

namespace App\Http\Controllers;

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Services\RosterHistoryReadinessService;
use App\Services\RosterPlanningPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(RosterHistoryReadinessService $history, RosterPlanningPeriodService $periods): Response
    {
        $currentMonth = $periods->operationalMonth();
        $planningMonth = $periods->planningMonth($currentMonth);
        $months = collect([$currentMonth, $planningMonth]);
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

        $currentMonthSummary = $monthSummaries[0];
        $nextMonthSummary = $monthSummaries[1];
        $initialSetupRequired = $nextMonthSummary['history_readiness']['action'] === 'initial_setup'
            && ! $nextMonthSummary['history_readiness']['ready'];
        $initialBaseline = DoctorMonthlyWorkload::query()
            ->where('source', DoctorMonthlyWorkloadSource::ManualInitial)
            ->orderBy('year')
            ->orderBy('month')
            ->first();
        $initialSetupMonth = $initialSetupRequired
            ? CarbonImmutable::create($nextMonthSummary['history_readiness']['year'], $nextMonthSummary['history_readiness']['month'], 1)
            : ($initialBaseline === null
                ? $periods->initialSetupMonth()
                : CarbonImmutable::create($initialBaseline->year, $initialBaseline->month, 1));

        return Inertia::render('dashboard', [
            'months' => $monthSummaries,
            'initialSetup' => [
                'required' => $initialSetupRequired,
                'month' => [
                    'year' => $initialSetupMonth->year,
                    'month' => $initialSetupMonth->month,
                    'label' => $initialSetupMonth->format('F Y'),
                ],
            ],
            'primaryMonth' => $nextMonthSummary,
            'secondaryMonth' => $currentMonthSummary['status'] === 'not_started' ? null : $currentMonthSummary,
        ]);
    }
}
