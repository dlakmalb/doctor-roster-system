<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use App\Models\User;
use App\Services\RosterAssignmentGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RosterAssignmentRegenerationController extends Controller
{
    public function __invoke(int $year, int $month, Request $request, RosterAssignmentGenerator $generator): RedirectResponse
    {
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();

        /** @var User $admin */
        $admin = $request->user();
        $generator->regenerate($roster, $admin);

        return to_route('rosters.show', ['year' => $year, 'month' => $month]);
    }
}
