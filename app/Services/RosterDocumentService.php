<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;

class RosterDocumentService
{
    /**
     * @return array{month: string, status: string, is_draft: bool, rows: list<array{date: string, shift: string, time: string, main: list<string>, optional: list<string>}>}
     */
    public function build(Roster $roster): array
    {
        $roster->load(['shifts.shiftType', 'shifts.assignments.doctor']);
        $rows = array_values($roster->shifts
            ->sort(fn (RosterShift $first, RosterShift $second): int => [
                $first->shift_date->toDateString(), $first->shiftType->start_time, $first->id,
            ] <=> [
                $second->shift_date->toDateString(), $second->shiftType->start_time, $second->id,
            ])
            ->map(fn (RosterShift $shift): array => [
                'date' => $shift->shift_date->format('D, M j'),
                'shift' => $shift->shiftType->name,
                'time' => $this->time($shift),
                'main' => $this->slots($shift, RosterAssignmentRole::Main),
                'optional' => $this->slots($shift, RosterAssignmentRole::Optional),
            ])->all());

        return [
            'month' => CarbonImmutable::create($roster->year, $roster->month, 1)->format('F Y'),
            'status' => $roster->status->value,
            'is_draft' => $roster->status === RosterStatus::Draft,
            'rows' => $rows,
        ];
    }

    private function time(RosterShift $shift): string
    {
        $type = $shift->shiftType;

        return CarbonImmutable::parse($type->start_time)->format('g:i A').' – '.CarbonImmutable::parse($type->end_time)->format('g:i A')
            .($type->is_overnight ? ' (+1 day)' : '');
    }

    /** @return list<string> */
    private function slots(RosterShift $shift, RosterAssignmentRole $role): array
    {
        $required = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
        $assignments = $shift->assignments->where('role', $role)->keyBy('slot_number');
        $slots = [];

        for ($number = 1; $number <= $required; $number++) {
            /** @var RosterAssignment|null $assignment */
            $assignment = $assignments->get($number);
            $slots[] = $assignment === null ? 'UNFILLED' : $assignment->doctor->short_code.' - '.$assignment->doctor->name;
        }

        return $slots;
    }
}
