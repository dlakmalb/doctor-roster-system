<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
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
            $doctors = Doctor::query()->where('is_active', true)->get();
            $this->participation->ensureRosterSnapshot($roster, $doctors);
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
            $preferredRequests = DoctorRequest::query()
                ->where('request_type', DoctorRequestType::PreferredWork->value)
                ->whereBetween('request_date', [$firstDate, $lastDate])->get();
            $monthlyWeekdayPreferences = DoctorMonthlyWeekdayPreference::query()
                ->where('year', $roster->year)->where('month', $roster->month)->get();

            if (! $replace) {
                foreach ($shifts as $shift) {
                    foreach ($shift->assignments as $assignment) {
                        if (isset($restrictedShiftTypes[$assignment->doctor_id][$shift->shift_type_id])) {
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

            $this->ranker->initialize($shifts, $preferredRequests, $previousHistory, $firstDate, $restrictedShiftTypes, $monthlyWeekdayPreferences);
            $assignments = $this->recovery->plan($shifts, $doctors, $excludedDoctorIds, $dayOffRequests, $previousHistory, $this->ranker, $restrictedShiftTypes);

            if ($replace) {
                RosterAssignment::query()->whereIn('roster_shift_id', $shifts->modelKeys())->delete();
            }
            foreach ($assignments as $assignment) {
                RosterAssignment::create($assignment);
            }

            $roster->update(['last_generated_at' => now(), 'updated_by' => $admin->id]);
        });

        $undo = session()->get(RosterManualEditService::UNDO_KEY);
        if (is_array($undo) && ($undo['roster_id'] ?? null) === $roster->id) {
            session()->forget(RosterManualEditService::UNDO_KEY);
        }
    }
}
