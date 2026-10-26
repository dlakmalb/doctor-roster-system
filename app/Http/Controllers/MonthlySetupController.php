<?php

namespace App\Http\Controllers;

use App\Services\MonthlySetupService;
use Inertia\Inertia;
use Inertia\Response;

class MonthlySetupController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(int $year, int $month, MonthlySetupService $monthlySetup): Response
    {
        return Inertia::render('monthly-setup', $monthlySetup->build($year, $month));
    }
}
