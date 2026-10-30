<?php

namespace App\Http\Controllers;

use App\Services\MonthlySetupService;
use App\Services\RosterPlanningPeriodService;
use Inertia\Inertia;
use Inertia\Response;

class MonthlySetupController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        int $year,
        int $month,
        MonthlySetupService $monthlySetup,
        RosterPlanningPeriodService $periods,
    ): Response {
        $isPlanningPeriod = $periods->isPlanningPeriod($year, $month);

        return Inertia::render('monthly-setup', [
            ...$monthlySetup->build($year, $month, $isPlanningPeriod),
            'isPlanningPeriod' => $isPlanningPeriod,
            'weekendPairConfigurationUrl' => route('weekend-pairs.show', ['year' => $year, 'month' => $month]),
        ]);
    }
}
