<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class RosterPlanningPeriodService
{
    public function operationalMonth(): CarbonImmutable
    {
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
