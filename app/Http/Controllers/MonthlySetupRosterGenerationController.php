<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GenerateRosterFromMonthlySetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MonthlySetupRosterGenerationController extends Controller
{
    public function __invoke(int $year, int $month, Request $request, GenerateRosterFromMonthlySetup $generateRoster): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $generateRoster->handle($year, $month, $admin);

        return to_route('rosters.show', ['year' => $year, 'month' => $month]);
    }
}
