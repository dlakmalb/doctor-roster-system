<?php

namespace App\Services;

use App\Enums\RosterStatus;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\Roster;
use App\Models\RosterShift;
use App\Models\WeekendRotationConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WeekendGroupRotationService
{
    /** @return array{configured: bool, error: string|null, assignments: array<int, Collection<int, DoctorWeekendGroupMembership>>, expected: array<string, string>} */
    public function forMonth(int $year, int $month, Collection $doctors, Collection $shifts): array
    {
        $dates = $shifts->filter(fn (RosterShift $shift): bool => str_starts_with($shift->shiftType->code, 'weekend_'))
            ->map(fn (RosterShift $shift): CarbonImmutable => CarbonImmutable::instance($shift->shift_date))
            ->map(fn (CarbonImmutable $date): CarbonImmutable => $date->isSunday() ? $date->subDay() : $date)
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        return $this->resolve($year, $month, $doctors, $dates);
    }

    /** @return array{configured: bool, error: string|null, assignments: array<int, Collection<int, DoctorWeekendGroupMembership>>, expected: array<string, string>} */
    public function forCalendarMonth(int $year, int $month, Collection $doctors): array
    {
        return $this->resolve($year, $month, $doctors, $this->weekendStartsForMonth($year, $month));
    }

    /** @return Collection<int, CarbonImmutable> */
    public function weekendStartsForMonth(int $year, int $month): Collection
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth();
        $dates = collect();
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if ($date->isSaturday()) {
                $dates->push($date);
            } elseif ($date->isSunday()) {
                $dates->push($date->subDay());
            }
        }

        return $dates->unique(fn (CarbonImmutable $date): string => $date->toDateString())->values();
    }

    /** @param Collection<int, CarbonImmutable> $dates
     * @return array{configured: bool, error: string|null, assignments: array<int, Collection<int, DoctorWeekendGroupMembership>>, expected: array<string, string>}
     */
    private function resolve(int $year, int $month, Collection $doctors, Collection $dates): array
    {
        $configuration = WeekendRotationConfiguration::query()->first();
        if ($configuration === null) {
            return ['configured' => false, 'error' => null, 'assignments' => [], 'expected' => []];
        }
        if (! in_array($configuration->anchor_group, ['A', 'B'], true)
            || ! $configuration->anchor_saturday->isSaturday()) {
            return $this->invalid('Weekend rotation configuration has an invalid anchor. Set a Saturday anchor and Group A or B.');
        }

        $last = CarbonImmutable::create($year, $month, 1)->endOfMonth()->addWeek();
        $memberships = DoctorWeekendGroupMembership::query()
            ->where('effective_from_saturday', '<=', $last->toDateString())
            ->orderBy('effective_from_saturday')
            ->get()
            ->groupBy('doctor_id');

        $invalidGroup = DoctorWeekendGroupMembership::query()->whereNotIn('group_code', ['A', 'B'])->exists();
        if ($invalidGroup) {
            return $this->invalid('Weekend group membership contains a value other than A or B. Correct the membership configuration.');
        }

        $expected = [];
        foreach ($dates as $saturday) {
            $expected[$saturday->toDateString()] = $this->scheduledGroupFor($configuration, $saturday);
        }

        $assignments = [];
        foreach ($doctors as $doctor) {
            $rows = $memberships->get($doctor->id, collect());
            if ($rows->isEmpty()) {
                return $this->invalid("Weekend group membership is incomplete for {$doctor->name}. Assign this doctor to Group A or B before generating.");
            }
            foreach (array_keys($expected) as $saturday) {
                $effectiveMembership = $rows->last(fn (DoctorWeekendGroupMembership $row): bool => $row->effective_from_saturday->toDateString() <= $saturday);
                if ($effectiveMembership === null) {
                    return $this->invalid("Weekend group membership for {$doctor->name} is not effective by $saturday. Add a membership effective on or before that Saturday.");
                }
                if (! in_array($effectiveMembership->group_code, ['A', 'B'], true)) {
                    return $this->invalid("{$doctor->name} has no valid Group A or Group B membership for the weekend of $saturday.");
                }
            }
            $assignments[$doctor->id] = $rows;
        }

        return ['configured' => true, 'error' => null, 'assignments' => $assignments, 'expected' => $expected];
    }

    /** @param array<int, Collection<int, DoctorWeekendGroupMembership>> $memberships */
    public function groupFor(array $memberships, int $doctorId, string $saturday): ?string
    {
        $membership = ($memberships[$doctorId] ?? collect())
            ->filter(fn (DoctorWeekendGroupMembership $row): bool => $row->effective_from_saturday->toDateString() <= $saturday)
            ->last();

        return $membership?->group_code;
    }

    public function assertChangeAllowed(string $effectiveSaturday): void
    {
        $effective = CarbonImmutable::parse($effectiveSaturday);
        if (! $effective->isSaturday()) {
            throw ValidationException::withMessages(['effective_from_saturday' => 'Membership changes must take effect from a Saturday weekend start.']);
        }

        $final = Roster::query()->where('status', RosterStatus::Final->value)
            ->whereHas('shifts', fn ($query) => $query->whereDate('shift_date', '>=', $effective->toDateString()))
            ->exists();
        if ($final) {
            throw ValidationException::withMessages(['effective_from_saturday' => 'This change would affect a Final roster. Choose a later Saturday.']);
        }
    }

    public function scheduledGroupFor(WeekendRotationConfiguration $configuration, CarbonImmutable $saturday): string
    {
        $distance = abs((int) $configuration->anchor_saturday->diffInWeeks($saturday, false));

        return $distance % 2 === 0 ? $configuration->anchor_group : ($configuration->anchor_group === 'A' ? 'B' : 'A');
    }

    /** @return array{configured: true, error: string, assignments: array{}, expected: array{}} */
    private function invalid(string $message): array
    {
        return ['configured' => true, 'error' => $message, 'assignments' => [], 'expected' => []];
    }
}
