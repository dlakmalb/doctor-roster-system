<?php

namespace App\Http\Controllers;

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Services\RosterDraftContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RosterAssignmentOptionsController extends Controller
{
    public function __invoke(int $year, int $month, Request $request, RosterDraftContext $context): JsonResponse
    {
        $input = $request->validate([
            'shift_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(['main', 'optional'])],
            'slot_number' => ['required', 'integer', 'min:1'],
            'expected_assignment_id' => ['present', 'nullable', 'integer'],
            'expected_doctor_id' => ['present', 'nullable', 'integer'],
        ]);
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
        if ($roster->status !== RosterStatus::Draft) {
            throw ValidationException::withMessages(['edit' => 'Only a Draft roster can be edited.']);
        }
        $context->load($roster);
        $role = RosterAssignmentRole::from($input['role']);
        $shift = $context->shift($input['shift_id'], $role, $input['slot_number']);
        $current = $context->assignment($shift->id, $role, $input['slot_number']);
        if ($current?->id !== $input['expected_assignment_id'] || $current?->doctor_id !== $input['expected_doctor_id']) {
            throw ValidationException::withMessages(['edit' => 'This roster slot changed since the page was loaded. Refresh and try again.']);
        }
        $state = $context->state();
        unset($state[$context->key($shift->id, $role, $input['slot_number'])]);
        $options = [];
        foreach ($context->doctors as $doctor) {
            $source = $shift->assignments->first(fn (RosterAssignment $assignment): bool => $assignment->doctor_id === $doctor->id && ($assignment->role !== $role || $assignment->slot_number !== $input['slot_number']));
            $testing = $state;
            if ($source?->role !== null && $source->role !== $role) {
                unset($testing[$context->key($shift->id, $source->role, $source->slot_number)]);
            }
            $reasons = $source?->role === $role ? ['Already assigned to another '.$role->value.' slot on this shift.'] : $context->hardReasons($doctor->id, $shift, $testing);
            $options[] = [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'short_code' => $doctor->short_code,
                'eligible' => $reasons === [],
                'reasons' => $reasons,
                'preferred_work' => $context->prefers($doctor->id, $shift),
                'source_assignment_id' => $source?->role !== $role ? $source?->id : null,
                ...$context->metrics($doctor->id, $testing),
                'dimensions' => $reasons === [] ? $context->dimensions($doctor->id, $shift, $role, $testing) : null,
            ];
        }
        usort($options, fn (array $left, array $right): int => ($left['eligible'] === $right['eligible'])
            ? (($left['dimensions'] ?? []) <=> ($right['dimensions'] ?? [])) ?: ($left['name'] <=> $right['name'])
            : ($left['eligible'] ? -1 : 1));
        foreach ($options as &$option) {
            unset($option['dimensions']);
        }

        return response()->json(['options' => $options]);
    }
}
