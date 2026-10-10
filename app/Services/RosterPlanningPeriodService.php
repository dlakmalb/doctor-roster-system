<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class RosterPlanningPeriodService
{
    public function operationalMonth(): CarbonImmutable
    {
        $configuredMonth = config('roster.uat_operational_month');

        if (
            app()->environment('local')
            && $configuredMonth !== null
            && $configuredMonth !== ''
            && $configuredMonth !== false
        ) {
            if (! is_string($configuredMonth)
                || preg_match('/\A([1-9][0-9]{3})-(0[1-9]|1[0-2])\z/', $configuredMonth, $matches) !== 1) {
                throw new InvalidArgumentException('ROSTER_UAT_OPERATIONAL_MONTH must use a valid YYYY-MM month between 1000-01 and 9999-12.');
            }

            return CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1)->startOfMonth();
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    public function planningMonth(?CarbonImmutable $operationalMonth = null): CarbonImmutable
    {
        return ($operationalMonth ?? $this->operationalMonth())->addMonth();
    }

    public function initialSetupMonth(): CarbonImmutable
    {
        return $this->operationalMonth();
    }

    public function isPlanningPeriod(int $year, int $month, ?CarbonImmutable $operationalMonth = null): bool
    {
        $planningMonth = $this->planningMonth($operationalMonth);

        return $year === $planningMonth->year && $month === $planningMonth->month;
    }
}
