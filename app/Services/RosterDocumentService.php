<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;
use LogicException;

class RosterDocumentService
{
    /**
     * @return array{month: string, status: string, is_draft: bool, rows: list<array{date: string, day: string, number: int, weekend: bool, cells: list<array{main: list<string>, optional: list<string>}>}>, doctors: list<array{code: string, name: string}>, schedules: list<array{label: string, time: string}>}
     */
    public function build(Roster $roster): array
    {
        $roster->load(['shifts.shiftType', 'shifts.assignments.doctor', 'shifts.capacitySnapshot']);
        foreach ($roster->shifts as $shift) {
            try {
                $capacity = $shift->savedCapacity();
            } catch (LogicException $exception) {
                abort(409, "Cannot generate this roster document: {$exception->getMessage()}");
            }
            foreach ($shift->assignments as $assignment) {
                $limit = $assignment->role === RosterAssignmentRole::Main ? $capacity->main_count : $capacity->optional_count;
                if ($assignment->slot_number < 1 || $assignment->slot_number > $limit) {
                    abort(409, "Cannot generate this roster document: {$shift->shift_date->format('M j')} {$shift->shiftType->name} has an assignment outside its saved {$assignment->role->value} capacity.");
                }
            }
        }
        $month = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $shiftsByDate = $roster->shifts->groupBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString());
        $rows = [];

        for ($date = $month; $date->month === $month->month; $date = $date->addDay()) {
            $shifts = $shiftsByDate->get($date->toDateString(), collect())
                ->keyBy(fn (RosterShift $shift): string => $shift->shiftType->code);
            $day = $this->cell($shifts->get($date->isWeekend() ? 'weekend_day' : 'weekday_day'));
            $evening = $this->cell($shifts->get('weekday_evening'));
            $night = $this->cell($shifts->get($date->isWeekend() ? 'weekend_night' : 'weekday_night'));

            if ($date->isWeekend()) {
                $evening = ['main' => [], 'optional' => array_slice($day['optional'], 3)];
                $day['optional'] = array_slice($day['optional'], 0, 3);
            }

            $rows[] = [
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'number' => $date->day,
                'weekend' => $date->isWeekend(),
                'cells' => [$day, $evening, $night],
            ];
        }

        $doctors = $roster->shifts->flatMap(fn (RosterShift $shift) => $shift->assignments)
            ->map(fn (RosterAssignment $assignment): Doctor => $assignment->doctor)
            ->unique('id')
            ->sortBy(fn (Doctor $doctor): array => [$doctor->short_code !== 'N', $doctor->short_code])
            ->values()
            ->map(fn (Doctor $doctor): array => ['code' => $doctor->short_code, 'name' => $doctor->name])->all();
        $schedules = $roster->shifts->unique('shift_type_id')
            ->sortBy(fn (RosterShift $shift): array => [$shift->shiftType->code, $shift->shiftType->start_time])
            ->map(fn (RosterShift $shift): array => ['label' => $shift->shiftType->name, 'time' => $this->time($shift)])
            ->values()->all();

        return [
            'month' => $month->format('F Y'),
            'status' => $roster->status->value,
            'is_draft' => $roster->status === RosterStatus::Draft,
            'rows' => $rows,
            'doctors' => $doctors,
            'schedules' => $schedules,
        ];
    }

    /** @return array{main: list<string>, optional: list<string>} */
    private function cell(?RosterShift $shift): array
    {
        return $shift === null ? ['main' => [], 'optional' => []] : [
            'main' => $this->slots($shift, RosterAssignmentRole::Main),
            'optional' => $this->slots($shift, RosterAssignmentRole::Optional),
        ];
    }

    private function time(RosterShift $shift): string
    {
        $type = $shift->shiftType;

        return CarbonImmutable::parse($type->start_time)->format('g:i A').' - '.CarbonImmutable::parse($type->end_time)->format('g:i A')
            .($type->is_overnight ? ' (+1 day)' : '');
    }

    /** @return list<string> */
    private function slots(RosterShift $shift, RosterAssignmentRole $role): array
    {
        $required = $shift->savedCapacityForRole($role);
        $assignments = $shift->assignments->where('role', $role)->keyBy('slot_number');
        $slots = [];

        for ($number = 1; $number <= $required; $number++) {
            /** @var RosterAssignment|null $assignment */
            $assignment = $assignments->get($number);
            $slots[] = $assignment === null ? '-' : $assignment->doctor->short_code;
        }

        return $slots;
    }
}
