<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\Roster;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;

class RosterDraftValidationService
{
    public function __construct(private RosterDraftContext $context, private RosterCandidateRanker $ranker, private RosterPlanningHistoryService $history, private WeekendGroupRotationService $weekendRotation) {}

    /** @return list<array{severity: string, code: string, message: string, target: string}> */
    public function validate(Roster $roster): array
    {
        $this->context->load($roster);
        $state = $this->context->state();
        $items = [];
        if (! $this->context->participationSnapshotIntegrityMatches) {
            $items[] = $this->item('Error', 'participation_snapshot_integrity', 'This roster has incomplete or mismatched saved participation records.', 'conflicts');
        }
        if ($roster->status === RosterStatus::Draft && ! $this->context->participationPopulationMatches) {
            $items[] = $this->item('Error', 'participation_population_mismatch', 'Active doctor statuses no longer match this Draft roster’s saved participation. Restore the active statuses to match the saved population before finalizing.', 'conflicts');
        }
        foreach ($this->context->shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $capacity = $assignment->role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
                if ($assignment->slot_number < 1 || $assignment->slot_number > $capacity) {
                    $items[] = $this->item('Error', 'assignment_outside_capacity', "{$shift->shift_date->format('M j')} {$shift->shiftType->name} has an assignment outside its {$assignment->role->value} slot capacity.", "shift-$shift->id");
                }
            }
            foreach (RosterAssignmentRole::cases() as $role) {
                $capacity = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
                for ($slot = 1; $slot <= $capacity; $slot++) {
                    $key = $this->context->key($shift->id, $role, $slot);
                    $target = "slot-{$shift->id}-{$role->value}-$slot";
                    if (! isset($state[$key])) {
                        $items[] = $this->item(
                            $role === RosterAssignmentRole::Main ? 'Error' : 'Warning',
                            $role === RosterAssignmentRole::Main ? 'unfilled_main_slot' : 'unfilled_optional_slot',
                            "{$shift->shift_date->format('M j')} {$shift->shiftType->name} {$role->value} slot $slot is unfilled.",
                            $target,
                        );

                        continue;
                    }
                    $other = $state;
                    unset($other[$key]);
                    foreach ($this->context->hardReasons($state[$key], $shift, $other) as $reason) {
                        $items[] = $this->item('Error', 'hard_conflict', "{$shift->shift_date->format('M j')} {$shift->shiftType->name}: $reason", $target);
                    }
                }
            }
        }
        foreach ($this->context->preferred as $request) {
            if (! $this->context->preferenceFulfilled($request, $state)) {
                $targetShift = $this->context->shifts->first(fn (RosterShift $shift): bool => $shift->shift_type_id === $request->shift_type_id && $shift->shift_date->isSameDay($request->request_date));
                $items[] = $this->item('Warning', 'preferred_work_unfulfilled', "{$request->doctor->name}'s Preferred Work request for {$request->request_date->format('M j')} {$request->shiftType?->name} is not fulfilled.", $targetShift === null ? 'conflicts' : "shift-$targetShift->id");
            }
        }
        $weekends = [];
        $assignedDoctorIds = collect($state)->values()->unique();
        $participatingDoctorIds = DoctorMonthlyParticipation::query()
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->where('is_participating', true)
            ->pluck('doctor_id');
        $rotationDoctors = Doctor::query()->whereIn('id', $participatingDoctorIds->merge($assignedDoctorIds)->unique())->get();
        $rotation = $this->weekendRotation->forMonth(
            $roster->year,
            $roster->month,
            $rotationDoctors,
            $this->context->shifts->values(),
        );
        if ($rotation['error'] !== null) {
            $items[] = $this->item('Warning', 'weekend_group_configuration', $rotation['error'], 'conflicts');
        }
        foreach ($state as $key => $doctorId) {
            [$shiftId, $role] = explode(':', $key);
            if ($role !== RosterAssignmentRole::Main->value) {
                continue;
            }
            $shift = $this->context->shifts->get((int) $shiftId);
            $weekend = $rotation['configured'] && $rotation['error'] === null
                ? $this->ranker->rotationWeekendStart($shift)
                : $this->ranker->weekendKey($shift);
            if ($weekend !== null) {
                if ($rotation['configured'] && $rotation['error'] === null) {
                    $actualGroup = $this->weekendRotation->groupFor($rotation['assignments'], (int) $doctorId, $weekend);
                    $expectedGroup = $rotation['expected'][$weekend] ?? null;
                    if ($actualGroup !== null && $expectedGroup !== null && $actualGroup !== $expectedGroup) {
                        $doctor = $this->context->doctors->get((int) $doctorId);
                        $items[] = $this->item(
                            'Warning',
                            'weekend_group_exception',
                            "{$shift->shift_date->format('M j')} {$shift->shiftType->name}: {$doctor->name} (Group $actualGroup) is assigned during Group $expectedGroup's scheduled weekend. Review this cross-group exception.",
                            "shift-$shift->id",
                        );
                    }
                }
                $weekends[$doctorId][$weekend][] = $shift;
            }
        }
        foreach ($weekends as $doctorId => $periods) {
            foreach ($periods as $weekend => $shifts) {
                if (count($shifts) > 1 && ! ($rotation['configured'] && $rotation['error'] === null)) {
                    $doctor = $this->context->doctors->get((int) $doctorId);
                    $weekendStart = CarbonImmutable::parse($weekend);
                    $weekendEnd = $weekendStart->addDay();
                    $weekendRange = $weekendStart->format('M j')."\u{2013}".$weekendEnd->format(
                        $weekendStart->month === $weekendEnd->month ? 'j' : 'M j',
                    );
                    $items[] = $this->item('Warning', 'multiple_weekend_main', "$doctor->name has multiple Main duties during the weekend of $weekendRange.", 'shift-'.$shifts[0]->id);
                }
            }
        }

        if ($roster->last_generated_at !== null) {
            $previous = CarbonImmutable::create($roster->year, $roster->month, 1)->subMonth();
            $historyUpdatedAt = $this->history->freshness($roster->year, $roster->month);
            if ($historyUpdatedAt !== null && $historyUpdatedAt->greaterThan($roster->last_generated_at)) {
                $items[] = $this->item('Warning', 'stale_history', "{$previous->format('F Y')} history changed after this roster was generated. Review or regenerate this roster because fairness calculations may be stale.", 'conflicts');
            }
        }

        return $items;
    }

    /** @return array{severity: string, code: string, message: string, target: string} */
    private function item(string $severity, string $code, string $message, string $target): array
    {
        return compact('severity', 'code', 'message', 'target');
    }
}
