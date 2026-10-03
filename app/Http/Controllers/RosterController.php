<?php

namespace App\Http\Controllers;

use App\Enums\RosterAssignmentRole;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\User;
use App\Services\RosterDraftValidationService;
use App\Services\RosterManualEditService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RosterController extends Controller
{
    public function store(int $year, int $month, Request $request, RosterStructureService $structure): RedirectResponse
    {
        /** @var User $creator */
        $creator = $request->user();

        try {
            $structure->create($year, $month, $creator);
        } catch (UniqueConstraintViolationException $exception) {
            if (! Roster::query()->where('year', $year)->where('month', $month)->exists()) {
                throw $exception;
            }
        }

        return to_route('rosters.show', ['year' => $year, 'month' => $month]);
    }

    public function show(int $year, int $month, RosterDraftValidationService $validation): Response
    {
        $roster = Roster::query()
            ->where('year', $year)
            ->where('month', $month)
            ->with(['shifts' => fn ($query) => $query->orderBy('shift_date'), 'shifts.shiftType', 'shifts.assignments.doctor'])
            ->firstOrFail();

        $conflicts = $validation->validate($roster);
        $undo = session()->get(RosterManualEditService::UNDO_KEY);
        $days = $roster->shifts
            ->groupBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString())
            ->map(function (Collection $shifts, string $date): array {
                $startDate = CarbonImmutable::parse($date);

                return [
                    'date' => $date,
                    'label' => $startDate->format('l, M j'),
                    'shifts' => $shifts->sortBy(fn (RosterShift $shift): string => $shift->shiftType->start_time)
                        ->map(fn (RosterShift $shift): array => [
                            'id' => $shift->id,
                            'code' => $shift->shiftType->code,
                            'name' => $shift->shiftType->name,
                            'start_time' => substr($shift->shiftType->start_time, 0, 5),
                            'end_time' => substr($shift->shiftType->end_time, 0, 5),
                            'end_date_label' => $shift->shiftType->is_overnight ? $startDate->addDay()->format('M j') : null,
                            'main_count' => $shift->shiftType->main_count,
                            'optional_count' => $shift->shiftType->optional_count,
                            'main' => $this->slots($shift, RosterAssignmentRole::Main),
                            'optional' => $this->slots($shift, RosterAssignmentRole::Optional),
                        ])->values(),
                ];
            })->values();

        return Inertia::render('roster', [
            'month' => ['year' => $year, 'month' => $month, 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y')],
            'status' => $roster->status->value,
            'has_generated' => $roster->last_generated_at !== null,
            'has_assignments' => $roster->shifts->contains(fn (RosterShift $shift): bool => $shift->assignments->isNotEmpty()),
            'last_generated_at' => $roster->last_generated_at?->toDateTimeString(),
            'can_undo' => $roster->status->value === 'draft' && is_array($undo) && ($undo['roster_id'] ?? null) === $roster->id
                && ($undo['generated_at'] ?? null) === $roster->last_generated_at?->toDateTimeString(),
            'conflicts' => $conflicts,
            'summary' => [
                'shifts' => $roster->shifts->count(),
                'main_positions' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->main_count),
                'optional_positions' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->optional_count),
                'filled_main' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->assignments->where('role', RosterAssignmentRole::Main)->count()),
                'filled_optional' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->assignments->where('role', RosterAssignmentRole::Optional)->count()),
                'missing_main' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->main_count - $shift->assignments->where('role', RosterAssignmentRole::Main)->count()),
                'missing_optional' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->optional_count - $shift->assignments->where('role', RosterAssignmentRole::Optional)->count()),
            ],
            'days' => $days,
        ]);
    }

    /** @return list<array{slot_number: int, assignment_id: int|null, doctor_id: int|null, doctor: array{name: string, short_code: string}|null, error: bool}> */
    private function slots(RosterShift $shift, RosterAssignmentRole $role): array
    {
        $required = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
        $assignments = $shift->assignments->where('role', $role)->keyBy('slot_number');
        $slots = [];

        for ($slot = 1; $slot <= $required; $slot++) {
            /** @var RosterAssignment|null $assignment */
            $assignment = $assignments->get($slot);
            $slots[] = [
                'slot_number' => $slot,
                'assignment_id' => $assignment?->id,
                'doctor_id' => $assignment?->doctor_id,
                'doctor' => $assignment === null ? null : ['name' => $assignment->doctor->name, 'short_code' => $assignment->doctor->short_code],
                'error' => $assignment === null,
            ];
        }

        return $slots;
    }
}
