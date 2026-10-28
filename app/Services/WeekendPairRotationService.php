<?php

namespace App\Services;

use App\Models\DoctorWeekendGroupMembership;
use App\Models\WeekendPairConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class WeekendPairRotationService
{
    public function __construct(
        private WeekendGroupRotationService $groups,
        private WeekendPairConfigurationService $configurations,
    ) {}

    /** @return array<string, mixed>|null */
    public function forSaturday(string $date): ?array
    {
        try {
            $saturday = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['saturday' => 'A valid Saturday is required.']);
        }
        if (! $saturday || $saturday->toDateString() !== $date || ! $saturday->isSaturday()) {
            throw ValidationException::withMessages(['saturday' => 'A valid Saturday is required.']);
        }
        $groupAnchor = $this->configurations->groupAnchor();
        $group = $this->groups->scheduledGroupFor($groupAnchor, $saturday);
        $configuration = WeekendPairConfiguration::query()->with('doctors')->where('group_code', $group)
            ->whereDate('effective_saturday', '<=', $date)->orderByDesc('effective_saturday')->first();
        if ($configuration === null) {
            return null;
        }

        $doctorIds = $configuration->doctors->pluck('doctor_id')->map(fn ($id): int => (int) $id)->all();
        $positions = $configuration->doctors->pluck('position')->map(fn ($position): int => (int) $position)->all();
        $anchor = $configuration->pair_anchor_saturday;
        $days = (int) $anchor->diffInDays($saturday, false);
        if (! in_array((int) $configuration->mode, [6, 7], true)
            || count($doctorIds) !== (int) $configuration->mode
            || count(array_unique($doctorIds)) !== count($doctorIds)
            || $positions !== range(1, count($doctorIds))
            || ! $anchor->isSaturday() || $days < 0 || $days % 14 !== 0
            || $this->groups->scheduledGroupFor($groupAnchor, $anchor) !== $group
            || ! $configuration->effective_saturday->isSaturday()
            || $this->groups->scheduledGroupFor($groupAnchor, $configuration->effective_saturday) !== $group
            || (int) $configuration->pair_anchor_phase < 0 || (int) $configuration->pair_anchor_phase > 2
            || (int) $configuration->extra_day_anchor_phase < 0 || (int) $configuration->extra_day_anchor_phase > 6) {
            throw ValidationException::withMessages(['configuration' => 'The effective pair configuration is invalid.']);
        }

        $memberships = DoctorWeekendGroupMembership::query()
            ->whereIn('doctor_id', $doctorIds)
            ->whereDate('effective_from_saturday', '<=', $date)
            ->orderByDesc('effective_from_saturday')
            ->get()
            ->groupBy('doctor_id');
        foreach ($doctorIds as $doctorId) {
            $membership = $memberships->get($doctorId)?->first();
            if ($membership?->group_code !== $group) {
                throw ValidationException::withMessages([
                    'configuration' => "Doctor {$doctorId} does not have Group {$group} membership effective on {$date}. Update the pair configuration before planning this weekend.",
                ]);
            }
        }

        return $this->calculate($group, $saturday, $configuration, $doctorIds, intdiv($days, 14));
    }

    /** @param list<int> $doctorIds
     * @return array<string, mixed>
     */
    private function calculate(string $group, CarbonImmutable $saturday, WeekendPairConfiguration $configuration, array $doctorIds, int $occurrence): array
    {
        $start = ($occurrence + (int) $configuration->pair_anchor_phase) % 3;
        $working = array_slice($doctorIds, 0, 6);
        $extra = null;
        if ((int) $configuration->mode === 7) {
            $extraPosition = ($occurrence + (int) $configuration->extra_day_anchor_phase) % 7;
            $extraIndex = $extraPosition === 0 ? 6 : $extraPosition - 1;
            $extra = $doctorIds[$extraIndex];
            if ($extraIndex !== 6) {
                $working[$extraIndex] = $doctorIds[6];
            }
        }
        $pairs = array_chunk($working, 2);
        $day = $pairs[$start];
        $sundayDay = $pairs[($start + 2) % 3];
        if ($extra !== null) {
            $day[] = $extra;
            $sundayDay[] = $extra;
        }

        return [
            'group_code' => $group,
            'saturday' => $saturday->toDateString(),
            'sunday' => $saturday->addDay()->toDateString(),
            'configuration_id' => $configuration->id,
            'configuration_version' => $configuration->version,
            'group_occurrence' => $occurrence,
            'starting_pair' => $start + 1,
            'additional_day_doctor_id' => $extra,
            'shifts' => [
                'saturday_day' => $day,
                'saturday_night' => $pairs[($start + 1) % 3],
                'sunday_day' => $sundayDay,
                'sunday_night' => $pairs[$start],
            ],
        ];
    }
}
