<?php

namespace App\Services;

use App\Enums\RosterAssignmentRole;
use App\Models\Doctor;
use App\Models\DoctorRequest;
use App\Models\RosterShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DoctorAssignmentEligibilityService
{
    public function __construct(private RequestIntervalService $intervals) {}

    /**
     * @param  Collection<int, DoctorRequest>  $dayOffRequests
     * @param  Collection<int, RosterShift>  $assignedShifts
     * @return list<string>
     */
    public function conflicts(Doctor $doctor, RosterShift $candidate, bool $isExcluded, Collection $dayOffRequests, Collection $assignedShifts, ?CarbonInterface $previousNightStart = null, bool $checkActiveStatus = true, bool $isShiftRestricted = false): array
    {
        return $this->roleAwareConflicts(
            $doctor,
            $candidate,
            RosterAssignmentRole::Main,
            $isExcluded,
            true,
            $dayOffRequests,
            $assignedShifts->map(fn (RosterShift $shift): array => ['shift' => $shift, 'role' => RosterAssignmentRole::Main]),
            $previousNightStart,
            $checkActiveStatus,
            $isShiftRestricted,
        );
    }

    /**
     * @param  Collection<int, DoctorRequest>  $dayOffRequests
     * @param  Collection<int, array{shift: RosterShift, role: RosterAssignmentRole}>  $assignedAssignments
     * @return list<string>
     */
    public function roleAwareConflicts(Doctor $doctor, RosterShift $candidate, RosterAssignmentRole $candidateRole, bool $isExcluded, bool $isParticipating, Collection $dayOffRequests, Collection $assignedAssignments, ?CarbonInterface $previousNightStart = null, bool $checkActiveStatus = true, bool $isShiftRestricted = false): array
    {
        $conflicts = [];

        if ($checkActiveStatus && ! $doctor->is_active) {
            $conflicts[] = 'inactive_doctor';
        }

        if ($isExcluded) {
            $conflicts[] = 'monthly_exclusion';
        }

        if (! $isParticipating) {
            $conflicts[] = 'not_participating';
        }

        $isMain = $candidateRole === RosterAssignmentRole::Main;

        if ($isMain && $isShiftRestricted) {
            $conflicts[] = 'monthly_shift_restriction';
        }

        if ($isMain) {
            $candidateInterval = $this->intervals->forDate($candidate->shift_date, $candidate->shiftType);

            foreach ($dayOffRequests as $request) {
                if ($this->intervals->overlaps($candidateInterval, $this->intervals->forRequest($request))) {
                    $conflicts[] = 'day_off_overlap';
                    break;
                }
            }
        }

        foreach ($assignedAssignments as $assignment) {
            $conflict = $this->roleAwareShiftConflict($candidate, $candidateRole, $assignment['shift'], $assignment['role']);

            if ($conflict !== null && ! in_array($conflict, $conflicts, true)) {
                $conflicts[] = $conflict;
            }
        }

        if ($isMain && $previousNightStart !== null) {
            $conflict = $this->nightConflict(CarbonImmutable::instance($candidate->shift_date)->startOfDay(), $candidate->shiftType->is_overnight, CarbonImmutable::instance($previousNightStart)->startOfDay(), true);

            if ($conflict !== null && ! in_array($conflict, $conflicts, true)) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    public function roleAwareShiftConflict(RosterShift $candidate, RosterAssignmentRole $candidateRole, RosterShift $assignedShift, RosterAssignmentRole $assignedRole): ?string
    {
        $candidateDate = CarbonImmutable::instance($candidate->shift_date)->startOfDay();
        $assignedDate = CarbonImmutable::instance($assignedShift->shift_date)->startOfDay();

        if ($candidateDate->isSameDay($assignedDate)) {
            return 'same_start_date';
        }

        $intervalsOverlap = $this->intervals->overlaps(
            $this->intervals->forDate($candidate->shift_date, $candidate->shiftType),
            $this->intervals->forDate($assignedShift->shift_date, $assignedShift->shiftType),
        );

        if ($candidateRole === RosterAssignmentRole::Main && $assignedRole === RosterAssignmentRole::Main) {
            $nightConflict = $this->nightConflict($candidateDate, $candidate->shiftType->is_overnight, $assignedDate, $assignedShift->shiftType->is_overnight);

            if ($nightConflict !== null) {
                return $nightConflict;
            }
        }

        if ($intervalsOverlap) {
            return 'shift_time_overlap';
        }

        if ($candidateRole !== RosterAssignmentRole::Main || $assignedRole !== RosterAssignmentRole::Main) {
            return null;
        }

        return null;
    }

    public function shiftConflict(RosterShift $candidate, RosterShift $assignedShift): ?string
    {
        $candidateDate = CarbonImmutable::instance($candidate->shift_date)->startOfDay();
        $assignedDate = CarbonImmutable::instance($assignedShift->shift_date)->startOfDay();

        if ($candidateDate->isSameDay($assignedDate)) {
            return 'same_start_date';
        }

        return $this->nightConflict($candidateDate, $candidate->shiftType->is_overnight, $assignedDate, $assignedShift->shiftType->is_overnight);
    }

    private function nightConflict(CarbonImmutable $candidateDate, bool $candidateIsNight, CarbonImmutable $assignedDate, bool $assignedIsNight): ?string
    {
        if ($candidateDate->lessThan($assignedDate)) {
            $earlierDate = $candidateDate;
            $earlierIsNight = $candidateIsNight;
            $laterDate = $assignedDate;
            $laterIsNight = $assignedIsNight;
        } else {
            $earlierDate = $assignedDate;
            $earlierIsNight = $assignedIsNight;
            $laterDate = $candidateDate;
            $laterIsNight = $candidateIsNight;
        }

        if ($earlierIsNight && $earlierDate->addDay()->isSameDay($laterDate)) {
            return 'next_day_night_recovery';
        }

        if ($earlierIsNight && $laterIsNight && $earlierDate->addDays(2)->isSameDay($laterDate)) {
            return 'night_to_night_recovery';
        }

        return null;
    }
}
