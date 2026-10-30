<?php

namespace App\Http\Controllers;

use App\Enums\RosterAssignmentRole;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\User;
use App\Services\MonthlyShiftCapacityService;
use App\Services\RecreateDraftRoster;
use App\Services\RosterDraftValidationService;
use App\Services\RosterHistoryReadinessService;
use App\Services\RosterLifecycleService;
use App\Services\RosterManualEditService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class RosterController extends Controller
{
    public function recreate(int $year, int $month, Request $request, RecreateDraftRoster $recreation): RedirectResponse
    {
        $input = $request->validate([
            'legacy_main_count' => ['required', 'integer', 'min:0', 'max:255'],
            'confirmation' => ['required', 'accepted'],
        ]);
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
        /** @var User $admin */
        $admin = $request->user();
        $recreation->handle($roster, $admin, (int) $input['legacy_main_count'], true);

        return to_route('rosters.show', ['year' => $year, 'month' => $month]);
    }

    public function store(int $year, int $month, Request $request, RosterStructureService $structure): RedirectResponse
    {
        /** @var User $creator */
        $creator = $request->user();

        try {
            $structure->create($year, $month, $creator);
        } catch (UniqueConstraintViolationException $exception) {
            if (Roster::query()->where('year', $year)->where('month', $month)->doesntExist()) {
                throw $exception;
            }
        }

        return to_route('rosters.show', ['year' => $year, 'month' => $month]);
    }

    public function show(int $year, int $month, RosterDraftValidationService $validation, RosterHistoryReadinessService $history, RosterLifecycleService $lifecycle, MonthlyShiftCapacityService $capacities): Response
    {
        $roster = Roster::query()
            ->where('year', $year)
            ->where('month', $month)
            ->with(['shifts' => fn ($query) => $query->orderBy('shift_date'), 'shifts.shiftType', 'shifts.assignments.doctor', 'shifts.capacitySnapshot'])
            ->firstOrFail();

        $conflicts = $validation->validate($roster);
        $missingSlotSeverities = [];
        foreach ($conflicts as $conflict) {
            if (in_array($conflict['code'], ['unfilled_main_slot', 'unfilled_optional_slot'], true)) {
                $missingSlotSeverities[$conflict['target']] = $conflict['severity'];
            }
        }
        $undo = session()->get(RosterManualEditService::UNDO_KEY);
        $days = $roster->shifts
            ->groupBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString())
            ->map(function (Collection $shifts, string $date) use ($missingSlotSeverities): array {
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
                            'main_count' => $this->capacity($shift, RosterAssignmentRole::Main),
                            'optional_count' => $this->capacity($shift, RosterAssignmentRole::Optional),
                            'main' => $this->slots($shift, RosterAssignmentRole::Main, $missingSlotSeverities),
                            'optional' => $this->slots($shift, RosterAssignmentRole::Optional, $missingSlotSeverities),
                        ])->values(),
                ];
            })->values();

        return Inertia::render('roster', [
            'month' => ['year' => $year, 'month' => $month, 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y')],
            'status' => $roster->status->value,
            'legacy_weekend_day_main' => $roster->shifts->contains(fn (RosterShift $shift): bool => $shift->shiftType->code === 'weekend_day')
                ? $capacities->resolve($year, $month, 'weekend_day')['main_count'] : null,
            'can_reopen' => $roster->status->value === 'final' && $lifecycle->canReopen($roster),
            'history_readiness' => $history->forMonth($year, $month),
            'has_generated' => $roster->last_generated_at !== null,
            'has_assignments' => $roster->shifts->contains(fn (RosterShift $shift): bool => $shift->assignments->isNotEmpty()),
            'last_generated_at' => $roster->last_generated_at?->toDateTimeString(),
            'can_undo' => $roster->status->value === 'draft' && is_array($undo) && ($undo['roster_id'] ?? null) === $roster->id
                && ($undo['generated_at'] ?? null) === $roster->last_generated_at?->toDateTimeString(),
            'conflicts' => $conflicts,
            'summary' => [
                'shifts' => $roster->shifts->count(),
                'main_positions' => $this->capacityTotal($roster->shifts, RosterAssignmentRole::Main),
                'optional_positions' => $this->capacityTotal($roster->shifts, RosterAssignmentRole::Optional),
                'filled_main' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->assignments->where('role', RosterAssignmentRole::Main)->count()),
                'filled_optional' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->assignments->where('role', RosterAssignmentRole::Optional)->count()),
                'missing_main' => $this->missingSlotsTotal($roster->shifts, RosterAssignmentRole::Main),
                'missing_optional' => $this->missingSlotsTotal($roster->shifts, RosterAssignmentRole::Optional),
            ],
            'days' => $days,
        ]);
    }

    /**
     * @param  array<string, string>  $missingSlotSeverities
     * @return list<array{slot_number: int, assignment_id: int|null, doctor_id: int|null, doctor: array{name: string, short_code: string}|null, severity: string|null}>
     */
    private function slots(RosterShift $shift, RosterAssignmentRole $role, array $missingSlotSeverities): array
    {
        $assignments = $shift->assignments->where('role', $role)->keyBy('slot_number');
        $required = $this->capacity($shift, $role);
        if ($required === null) {
            return $assignments->sortKeys()->map(fn (RosterAssignment $assignment): array => [
                'slot_number' => $assignment->slot_number,
                'assignment_id' => $assignment->id,
                'doctor_id' => $assignment->doctor_id,
                'doctor' => ['name' => $assignment->doctor->name, 'short_code' => $assignment->doctor->short_code],
                'severity' => 'Error',
            ])->values()->all();
        }
        $slotNumbers = $required === 0 ? [] : range(1, $required);
        $slotNumbers = [...$slotNumbers, ...$assignments->keys()
            ->filter(fn (int|string $slot): bool => (int) $slot < 1 || (int) $slot > $required)
            ->map(fn (int|string $slot): int => (int) $slot)->all()];
        sort($slotNumbers);
        $slots = [];

        foreach (array_unique($slotNumbers) as $slot) {
            /** @var RosterAssignment|null $assignment */
            $assignment = $assignments->get($slot);
            $slots[] = [
                'slot_number' => $slot,
                'assignment_id' => $assignment?->id,
                'doctor_id' => $assignment?->doctor_id,
                'doctor' => $assignment === null ? null : ['name' => $assignment->doctor->name, 'short_code' => $assignment->doctor->short_code],
                'severity' => $slot < 1 || $slot > $required ? 'Error' : ($missingSlotSeverities["slot-{$shift->id}-{$role->value}-$slot"] ?? null),
            ];
        }

        return $slots;
    }

    private function capacity(RosterShift $shift, RosterAssignmentRole $role): ?int
    {
        try {
            return $shift->savedCapacityForRole($role);
        } catch (LogicException) {
            return null;
        }
    }

    /** @param Collection<int, RosterShift> $shifts */
    private function capacityTotal(Collection $shifts, RosterAssignmentRole $role): ?int
    {
        $total = 0;
        foreach ($shifts as $shift) {
            $capacity = $this->capacity($shift, $role);
            if ($capacity === null) {
                return null;
            }
            $total += $capacity;
        }

        return $total;
    }

    /** @param Collection<int, RosterShift> $shifts */
    private function missingSlotsTotal(Collection $shifts, RosterAssignmentRole $role): ?int
    {
        $missing = 0;
        foreach ($shifts as $shift) {
            $capacity = $this->capacity($shift, $role);
            if ($capacity === null) {
                return null;
            }
            $occupied = $shift->assignments
                ->where('role', $role)
                ->filter(fn (RosterAssignment $assignment): bool => $assignment->slot_number >= 1 && $assignment->slot_number <= $capacity)
                ->count();
            $missing += max(0, $capacity - $occupied);
        }

        return $missing;
    }
}
