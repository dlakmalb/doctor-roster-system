<?php

namespace App\Http\Controllers;

use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Models\User;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterHistoryReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RosterAssignmentGenerationController extends Controller
{
    public function __invoke(int $year, int $month, Request $request, RosterAssignmentGenerator $generator, RosterHistoryReadinessService $history): RedirectResponse
    {
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
        $readiness = $history->forMonth($year, $month);
        if ($roster->status === RosterStatus::Draft && ! $readiness['ready']) {
            throw ValidationException::withMessages(['roster' => $readiness['message'] ?? 'Previous-month history is not ready.']);
        }

        /** @var User $admin */
        $admin = $request->user();
        $generator->generate($roster, $admin);

        return to_route('rosters.show', ['year' => $year, 'month' => $month]);
    }
}
