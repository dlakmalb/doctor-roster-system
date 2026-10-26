<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterAssignmentGenerator
{
    public function __construct(
        private RosterCandidateRanker $ranker,
        private RosterAssignmentRecoveryService $recovery,
        private RosterPlanningHistoryService $history,
        private DoctorMonthlyParticipationService $participation,
        private WeekendGroupRotationService $weekendRotation,
        private PreviousNightBoundaryService $nightBoundary,
    ) {}

    public function generate(Roster $roster, User $admin): void
    {
        $this->run($roster, $admin, false);
    }

    public function regenerate(Roster $roster, User $admin): void
    {
        $this->run($roster, $admin, true);
    }

    private function run(Roster $roster, User $admin, bool $replace): void
    {
        DB::transaction(function () use ($roster, $admin, $replace): void {
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);
            if ($roster->status !== RosterStatus::Draft) {
                throw ValidationException::withMessages(['roster' => 'Assignments can only be generated for a Draft roster.']);
            }

            $shifts = $roster->shifts()->with(['shiftType', 'assignments'])->get();
            $previousNightDoctorIds = $this->nightBoundary->doctorIdsForMonth($roster->year, $roster->month);
            $doctors = Doctor::query()->where('is_active', true)->get();
            $participationSnapshot = $this->participation->ensureRosterSnapshot($roster, $doctors);
            $excludedDoctorIds = DoctorMonthlyExclusion::query()
                ->where('year', $roster->year)->where('month', $roster->month)
                ->pluck('doctor_id')->flip();
            $restrictedShiftTypes = [];
            foreach (DoctorMonthlyShiftRestriction::query()
                ->where('year', $roster->year)->where('month', $roster->month)
                ->get(['doctor_id', 'shift_type_id']) as $restriction) {
                $restrictedShiftTypes[$restriction->doctor_id][$restriction->shift_type_id] = true;
            }
            $firstDate = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
            $lastDate = $firstDate->endOfMonth();
            $dayOffRequests = DoctorRequest::query()->with('shiftType')
                ->where('request_type', DoctorRequestType::DayOff->value)
                ->whereBetween('request_date', [$firstDate->subDay(), $lastDate->addDay()])
                ->get()->toBase()->groupBy('doctor_id');
            $previousHistory = $this->history->forMonth($roster->year, $roster->month);
            $previousParticipation = $this->history->participationForMonth($roster->year, $roster->month, $previousHistory, $participationSnapshot->keys());
            $nightDate = $firstDate->subDay();
            $historyFingerprint = $this->history->fingerprintRows(
                $previousHistory,
                $previousParticipation,
                $this->nightBoundary->fingerprint($nightDate, $previousNightDoctorIds),
            );
            $preferredRequests = DoctorRequest::query()
                ->where('request_type', DoctorRequestType::PreferredWork->value)
                ->whereBetween('request_date', [$firstDate, $lastDate])->get();
            $monthlyWeekdayPreferences = DoctorMonthlyWeekdayPreference::query()
                ->where('year', $roster->year)->where('month', $roster->month)->get();
            $weekendRotation = $this->weekendRotation->forMonth($roster->year, $roster->month, $doctors, $shifts);
            if ($weekendRotation['error'] !== null) {
                throw ValidationException::withMessages(['roster' => $weekendRotation['error']]);
            }

            if (! $replace) {
                foreach ($shifts as $shift) {
                    foreach ($shift->assignments as $assignment) {
                        if ($excludedDoctorIds->has($assignment->doctor_id)) {
                            $doctor = $doctors->firstWhere('id', $assignment->doctor_id);
                            $doctorLabel = $doctor === null ? "doctor ID {$assignment->doctor_id}" : "{$doctor->name} ({$doctor->short_code})";

                            throw ValidationException::withMessages(['roster' => "{$doctorLabel} already has an assignment but is excluded for this month. Correct the assignment or regenerate the roster before generating."]);
                        }
                        if ($assignment->role === RosterAssignmentRole::Main && isset($restrictedShiftTypes[$assignment->doctor_id][$shift->shift_type_id])) {
                            throw ValidationException::withMessages(['roster' => 'An existing assignment violates a Monthly Shift Restriction. Correct it or regenerate the roster before generating.']);
                        }
                    }
                }
            }

            if ($replace) {
                foreach ($shifts as $shift) {
                    $shift->setRelation('assignments', collect());
                }
            }

            $this->ranker->initialize($shifts, $preferredRequests, $previousHistory, $firstDate, $restrictedShiftTypes, $monthlyWeekdayPreferences, $weekendRotation, $previousParticipation);
            $assignments = $this->recovery->plan($shifts, $doctors, $excludedDoctorIds, $dayOffRequests, $previousHistory, $this->ranker, $restrictedShiftTypes, $participationSnapshot, $previousNightDoctorIds);

            $lastNightDoctorIds = [];
            $shiftsById = $shifts->keyBy('id');
            foreach ($shifts as $shift) {
                if ($shift->shift_date->isSameDay($lastDate) && $shift->shiftType->is_overnight) {
                    array_push($lastNightDoctorIds, ...$shift->assignments->pluck('doctor_id')->all());
                }
            }
            foreach ($assignments as $assignment) {
                $shift = $shiftsById->get($assignment['roster_shift_id']);
                if ($shift !== null && $shift->shift_date->isSameDay($lastDate) && $shift->shiftType->is_overnight) {
                    $lastNightDoctorIds[] = $assignment['doctor_id'];
                }
            }
            $lastNightDoctorIds = collect(array_unique(array_map('intval', $lastNightDoctorIds)))->flip();
            $this->nightBoundary->assertNoFinalDependentConflict($roster->year, $roster->month, $lastNightDoctorIds);

            if ($replace) {
                RosterAssignment::query()->whereIn('roster_shift_id', $shifts->modelKeys())->delete();
            }
            foreach ($assignments as $assignment) {
                RosterAssignment::create($assignment);
            }

            $roster->update(['last_generated_at' => now(), 'generated_history_fingerprint' => $historyFingerprint, 'updated_by' => $admin->id]);
        });

        $undo = session()->get(RosterManualEditService::UNDO_KEY);
        if (is_array($undo) && ($undo['roster_id'] ?? null) === $roster->id) {
            session()->forget(RosterManualEditService::UNDO_KEY);
        }
    }
}
