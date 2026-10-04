<?php

namespace App\Services;

use App\Enums\ActualWorkExceptionType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** @phpstan-import-type BalancedRow from DoctorMonthlyWorkloadService */
class ActualWorkReviewService
{
    public function __construct(private DoctorMonthlyWorkloadService $workloads) {}

    public function save(Roster $roster, int $assignmentId, ActualWorkExceptionType $type, ?int $replacementDoctorId, User $admin): void
    {
        DB::transaction(function () use ($roster, $assignmentId, $type, $replacementDoctorId, $admin): void {
            $roster = $this->lockedFinalRoster($roster);
            $assignment = RosterAssignment::query()->whereKey($assignmentId)
                ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))->firstOrFail();
            if ($type === ActualWorkExceptionType::OptionalWorked && $assignment->role !== RosterAssignmentRole::Optional
                || $type !== ActualWorkExceptionType::OptionalWorked && $assignment->role !== RosterAssignmentRole::Main) {
                throw ValidationException::withMessages(['exception_type' => 'The exception is incompatible with this planned assignment.']);
            }
            if ($type === ActualWorkExceptionType::Replacement) {
                if ($replacementDoctorId === null || $replacementDoctorId === $assignment->doctor_id || ! Doctor::query()->whereKey($replacementDoctorId)->exists()) {
                    throw ValidationException::withMessages(['actual_doctor_id' => 'Choose another existing doctor for the replacement.']);
                }
                $actualDoctorId = $replacementDoctorId;
            } else {
                $actualDoctorId = $type === ActualWorkExceptionType::MainAbsent ? null : $assignment->doctor_id;
            }
            $existing = ActualWorkException::query()->where('planned_assignment_id', $assignment->id)->lockForUpdate()->get();
            if ($existing->count() > 1) {
                throw ValidationException::withMessages(['actual_work' => 'An assignment has multiple actual-work exceptions.']);
            }
            $exception = $existing->first() ?? new ActualWorkException;
            $exception->fill([
                'roster_shift_id' => $assignment->roster_shift_id,
                'planned_assignment_id' => $assignment->id,
                'exception_type' => $type,
                'actual_doctor_id' => $actualDoctorId,
                'recorded_by' => $admin->id,
            ])->save();
            $preview = $this->workloads->preview($roster);
            $this->refreshConfirmed($roster, $admin, $preview);
        });
    }

    public function remove(Roster $roster, int $exceptionId, User $admin): void
    {
        DB::transaction(function () use ($roster, $exceptionId, $admin): void {
            $roster = $this->lockedFinalRoster($roster);
            $exception = ActualWorkException::query()->whereKey($exceptionId)
                ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))->lockForUpdate()->firstOrFail();
            $exception->delete();
            $preview = $this->workloads->preview($roster);
            $this->refreshConfirmed($roster, $admin, $preview);
        });
    }

    public function confirm(Roster $roster, User $admin): void
    {
        DB::transaction(function () use ($roster, $admin): void {
            $roster = $this->lockedFinalRoster($roster);
            $this->workloads->persistRoster($roster);
            $roster->update(['actual_work_confirmed_at' => now(), 'actual_work_confirmed_by' => $admin->id]);
            $this->workloads->recalculateLater($roster->year, $roster->month);
        });
    }

    /** @param Closure(): void $change */
    public function changeMonthlyExclusion(int $year, int $month, User $admin, Closure $change): void
    {
        DB::transaction(function () use ($year, $month, $admin, $change): void {
            $roster = Roster::query()->where('year', $year)->where('month', $month)->lockForUpdate()->first();
            $change();
            if ($roster !== null && $roster->status === RosterStatus::Final && $roster->actual_work_confirmed_at !== null) {
                $this->refreshConfirmed($roster, $admin, $this->workloads->preview($roster));
            } elseif ($roster !== null && $roster->status === RosterStatus::Final) {
                $roster->touch();
            }
        });
    }

    private function lockedFinalRoster(Roster $roster): Roster
    {
        $locked = Roster::query()->lockForUpdate()->findOrFail($roster->id);
        if ($locked->status !== RosterStatus::Final) {
            throw ValidationException::withMessages(['roster' => 'Actual work can only be reviewed for a Final roster.']);
        }

        return $locked;
    }

    /** @param array{average: int, rows: list<BalancedRow>} $preview */
    private function refreshConfirmed(Roster $roster, User $admin, array $preview): void
    {
        if ($roster->actual_work_confirmed_at !== null) {
            $this->workloads->persistRoster($roster, $preview);
            $roster->update(['actual_work_confirmed_at' => now(), 'actual_work_confirmed_by' => $admin->id]);
            $this->workloads->recalculateLater($roster->year, $roster->month);
        }
    }
}
