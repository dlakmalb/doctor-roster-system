<?php

namespace App\Http\Middleware;

use App\Services\RosterHistoryReadinessService;
use App\Services\RosterPlanningPeriodService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMonthlySetupPlanningPeriod
{
    public function __construct(
        private RosterPlanningPeriodService $periods,
        private RosterHistoryReadinessService $history,
    ) {}

    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $year = (int) $request->route('year');
        $month = (int) $request->route('month');
        $operationalMonth = $this->periods->operationalMonth();
        $planningMonth = $this->periods->planningMonth($operationalMonth);
        $isRosterCreation = $request->routeIs('rosters.store');
        $action = $isRosterCreation
            ? "creating a {$planningMonth->format('F Y')} Draft roster"
            : "managing {$planningMonth->format('F Y')} Monthly Setup";

        if (! $this->periods->isPlanningPeriod($year, $month, $operationalMonth)) {
            $message = $isRosterCreation
                ? "Draft roster creation is currently available for {$planningMonth->format('F Y')}."
                : "Monthly Setup is currently available for {$planningMonth->format('F Y')}.";

            return to_route('dashboard')->with('status', $message);
        }

        $readiness = $this->history->forMonth($year, $month);
        if ($readiness['action'] === 'initial_setup' && ! $readiness['ready']) {
            $initialMonth = $operationalMonth;

            return to_route('initial-workload.show', ['year' => $initialMonth->year, 'month' => $initialMonth->month])
                ->with('status', "Complete Initial Setup for {$initialMonth->format('F Y')} before {$action}.");
        }

        return $next($request);
    }
}
