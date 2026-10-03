<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Models\Roster;
use App\Models\RosterShift;

class RosterDraftValidationService
{
    public function __construct(private RosterDraftContext $context, private RosterCandidateRanker $ranker) {}

    /** @return list<array{severity: string, message: string, target: string}> */
    public function validate(Roster $roster): array
    {
        $this->context->load($roster);
        $state = $this->context->state();
        $items = [];
        foreach ($this->context->shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $capacity = $assignment->role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
                if ($assignment->slot_number < 1 || $assignment->slot_number > $capacity) {
                    $items[] = $this->item('Error', "{$shift->shift_date->format('M j')} {$shift->shiftType->name} has an assignment outside its {$assignment->role->value} slot capacity.", "shift-$shift->id");
                }
            }
            foreach (RosterAssignmentRole::cases() as $role) {
                $capacity = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
                for ($slot = 1; $slot <= $capacity; $slot++) {
                    $key = $this->context->key($shift->id, $role, $slot);
                    $target = "slot-{$shift->id}-{$role->value}-$slot";
                    if (! isset($state[$key])) {
                        $items[] = $this->item('Error', "{$shift->shift_date->format('M j')} {$shift->shiftType->name} {$role->value} slot $slot is unfilled.", $target);

                        continue;
                    }
                    $other = $state;
                    unset($other[$key]);
                    foreach ($this->context->hardReasons($state[$key], $shift, $other) as $reason) {
                        $items[] = $this->item('Error', "{$shift->shift_date->format('M j')} {$shift->shiftType->name}: $reason", $target);
                    }
                }
            }
        }
        foreach ($this->context->preferred as $request) {
            if (! $this->context->preferenceFulfilled($request, $state)) {
                $targetShift = $this->context->shifts->first(fn (RosterShift $shift): bool => $shift->shift_type_id === $request->shift_type_id && $shift->shift_date->isSameDay($request->request_date));
                $items[] = $this->item('Warning', "{$request->doctor->name}'s Preferred Work request for {$request->request_date->format('M j')} {$request->shiftType?->name} is not fulfilled.", $targetShift === null ? 'conflicts' : "shift-$targetShift->id");
            }
        }
        $weekends = [];
        foreach ($state as $key => $doctorId) {
            [$shiftId, $role] = explode(':', $key);
            if ($role !== RosterAssignmentRole::Main->value) {
                continue;
            }
            $shift = $this->context->shifts->get((int) $shiftId);
            $weekend = $this->ranker->weekendKey($shift);
            if ($weekend !== null) {
                $weekends[$doctorId][$weekend][] = $shift;
            }
        }
        foreach ($weekends as $doctorId => $periods) {
            foreach ($periods as $weekend => $shifts) {
                if (count($shifts) > 1) {
                    $doctor = $this->context->doctors->get((int) $doctorId);
                    $items[] = $this->item('Warning', "$doctor->name has multiple Main duties in the weekend of $weekend.", 'shift-'.$shifts[0]->id);
                }
            }
        }

        return $items;
    }

    /** @return array{severity: string, message: string, target: string} */
    private function item(string $severity, string $message, string $target): array
    {
        return compact('severity', 'message', 'target');
    }
}
