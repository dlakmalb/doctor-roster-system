<?php

namespace App\Services;

use App\Models\DoctorRequest;
use App\Models\ShiftType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class RequestIntervalService
{
    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public function forDate(CarbonInterface $date, ?ShiftType $shiftType): array
    {
        $day = CarbonImmutable::instance($date)->startOfDay();

        if ($shiftType === null) {
            return ['start' => $day, 'end' => $day->addDay()];
        }

        $start = $day->setTimeFromTimeString($shiftType->start_time);
        $end = $day->setTimeFromTimeString($shiftType->end_time);

        if ($shiftType->is_overnight) {
            $end = $end->addDay();
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public function forRequest(DoctorRequest $request): array
    {
        return $this->forDate($request->request_date, $request->shiftType);
    }

    /**
     * @param  array{start: CarbonInterface, end: CarbonInterface}  $first
     * @param  array{start: CarbonInterface, end: CarbonInterface}  $second
     */
    public function overlaps(array $first, array $second): bool
    {
        return $first['start']->lt($second['end']) && $second['start']->lt($first['end']);
    }
}
