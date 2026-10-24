<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use App\Models\User;
use App\Services\RosterManualEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RosterManualEditController extends Controller
{
    public function edit(int $year, int $month, Request $request, RosterManualEditService $editor): JsonResponse
    {
        $input = $request->validate([
            'operation' => ['required', Rule::in(['replace', 'swap', 'clear'])],
            'shift_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(['main', 'optional'])],
            'slot_number' => ['required', 'integer', 'min:1'],
            'expected_assignment_id' => ['present', 'nullable', 'integer'],
            'expected_doctor_id' => ['present', 'nullable', 'integer'],
            'doctor_id' => ['required_if:operation,replace', 'nullable', 'integer'],
            'expected_source_assignment_id' => ['nullable', 'integer'],
            'target_shift_id' => ['required_if:operation,swap', 'integer'],
            'target_role' => ['required_if:operation,swap', Rule::in(['main', 'optional'])],
            'target_slot_number' => ['required_if:operation,swap', 'integer', 'min:1'],
            'target_expected_assignment_id' => ['required_if:operation,swap', 'integer'],
            'target_expected_doctor_id' => ['required_if:operation,swap', 'integer'],
            'confirm_soft_override' => ['boolean'],
        ]);
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
        /** @var User $admin */
        $admin = $request->user();

        return response()->json($editor->edit($roster, $admin, $request->string('operation')->toString(), $input, $request->boolean('confirm_soft_override')));
    }

    public function undo(int $year, int $month, Request $request, RosterManualEditService $editor): JsonResponse
    {
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
        /** @var User $admin */
        $admin = $request->user();
        $editor->undo($roster, $admin);

        return response()->json(['status' => 'saved']);
    }
}
