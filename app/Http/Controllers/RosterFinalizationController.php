<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use App\Models\User;
use App\Services\RosterLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RosterFinalizationController extends Controller
{
    public function __invoke(int $year, int $month, Request $request, RosterLifecycleService $lifecycle): JsonResponse
    {
        $input = $request->validate(['warning_signature' => ['nullable', 'string', 'size:64']]);
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
        /** @var User $admin */
        $admin = $request->user();

        $signature = isset($input['warning_signature']) ? (string) $input['warning_signature'] : null;

        return response()->json($lifecycle->finalize($roster, $admin, $signature));
    }
}
