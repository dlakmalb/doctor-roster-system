<?php

namespace App\Services;

use App\Models\Roster;
use App\Models\WeekendPairConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class WeekendDayCapacityService
{
    public function __construct(
        private MonthlyWeekendPairService $confirmations,
        private WeekendGroupRotationService $groups,
        private WeekendPairRotationService $pairs,
    ) {}

    /**
     * Legacy weekends deliberately retain the selected roster month's configured Main count.
     *
     * @return array<string, array{saturday: string, group: string|null, mode: int|null, main_count: int, configuration_id: int|null, source: string}>
     */
    public function forMonth(int $year, int $month, int $legacyMainCount): array
    {
        $states = [];
        $weekends = [];
        foreach ($this->groups->weekendStartsForMonth($year, $month) as $saturday) {
            $key = $saturday->toDateString();
            $enabled = WeekendPairConfiguration::query()->whereDate('effective_saturday', '<=', $key)->exists();
            if (! $enabled) {
                $weekends[$key] = ['saturday' => $key, 'group' => null, 'mode' => null, 'main_count' => $legacyMainCount, 'configuration_id' => null, 'source' => 'legacy'];

                continue;
            }

            $period = sprintf('%04d-%02d', $year, $month);
            $states[$period] ??= $this->confirmedState($year, $month);
            $state = $states[$period];
            $historical = $saturday->year !== $year || $saturday->month !== $month;
            if ($historical) {
                $period = $saturday->format('Y-m');
                $states[$period] ??= $this->confirmedState($saturday->year, $saturday->month);
                $state = $states[$period];
            }

            try {
                $plan = $this->pairs->forSaturday($key);
                $group = $plan['group_code'] ?? null;
                $configuration = $state['groups'][$group]['configuration'] ?? null;
                $confirmedId = $state['confirmation']['configuration_ids'][$group] ?? null;
                if ($plan === null || $configuration === null
                    || $confirmedId !== $plan['configuration_id'] || $configuration['id'] !== $confirmedId
                    || ! in_array($configuration['mode'], [6, 7], true)) {
                    throw ValidationException::withMessages(['configuration' => 'The scheduled group has no compatible confirmed effective pair version.']);
                }
                if ($historical && ($states[sprintf('%04d-%02d', $year, $month)]['confirmation']['boundary_configuration_id'] ?? null) !== $confirmedId) {
                    throw ValidationException::withMessages(['configuration' => 'The Sunday boundary does not reference the confirmed historical Saturday version.']);
                }
                if ($historical) {
                    $this->assertHistoricalSnapshotCompatible($saturday, $configuration['mode'] === 7 ? 3 : 2);
                }
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'weekend_capacity' => "Weekend {$key}: ".implode(' ', array_merge(...array_values($exception->errors()))).' Review and confirm Monthly Weekend Pairs before creating the roster.',
                ]);
            }

            $weekends[$key] = [
                'saturday' => $key, 'group' => $group, 'mode' => $configuration['mode'],
                'main_count' => $configuration['mode'] === 7 ? 3 : 2,
                'configuration_id' => $confirmedId, 'source' => 'confirmed_pairs',
            ];
        }

        return $weekends;
    }

    private function assertHistoricalSnapshotCompatible(CarbonImmutable $saturday, int $mainCount): void
    {
        $roster = Roster::query()->where('year', $saturday->year)->where('month', $saturday->month)->first();
        if ($roster === null) {
            return;
        }

        $shift = $roster->shifts()->whereDate('shift_date', $saturday->toDateString())
            ->whereHas('shiftType', fn ($query) => $query->where('code', 'weekend_day'))->first();
        try {
            $compatible = $shift !== null && $shift->savedCapacity()->main_count === $mainCount;
        } catch (\LogicException) {
            $compatible = false;
        }
        if (! $compatible) {
            throw ValidationException::withMessages([
                'configuration' => 'The preceding Saturday saved Weekend Day Main capacity is missing or conflicts with its confirmed pair mode. Review the historical roster boundary; existing snapshots cannot be overwritten.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function confirmedState(int $year, int $month): array
    {
        $state = $this->confirmations->load($year, $month);
        if ($state['status'] !== 'confirmed') {
            $period = CarbonImmutable::create($year, $month, 1)->format('F Y');
            throw ValidationException::withMessages([
                'weekend_capacity' => "{$period} weekend pair confirmation is missing or requires attention. Review participation and Weekend Group Management, then confirm Monthly Weekend Pairs before creating the roster. ".implode(' ', $state['issues']),
            ]);
        }

        return $state;
    }
}
