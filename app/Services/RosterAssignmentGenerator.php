<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterAssignmentGenerator
{
    public function __construct(private RosterCandidateRanker $ranker, private RosterAssignmentRecoveryService $recovery) {}

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
            $excludedDoctorIds = DoctorMonthlyExclusion::query()
                ->where('year', $roster->year)->where('month', $roster->month)
                ->pluck('doctor_id')->flip();
            $firstDate = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
            $lastDate = $firstDate->endOfMonth();
            $dayOffRequests = DoctorRequest::query()->with('shiftType')
                ->where('request_type', DoctorRequestType::DayOff->value)
                ->whereBetween('request_date', [$firstDate->subDay(), $lastDate->addDay()])
                ->get()->toBase()->groupBy('doctor_id');
            $previousMonth = $firstDate->subMonth();
            $previousHistory = DoctorMonthlyWorkload::query()
                ->where('year', $previousMonth->year)->where('month', $previousMonth->month)
                ->get()->keyBy('doctor_id');
            $preferredRequests = DoctorRequest::query()
                ->where('request_type', DoctorRequestType::PreferredWork->value)
                ->whereBetween('request_date', [$firstDate, $lastDate])->get();

            if ($replace) {
                foreach ($shifts as $shift) {
                    $shift->setRelation('assignments', collect());
                }
            }

            $this->ranker->initialize($shifts, $preferredRequests, $previousHistory, $firstDate);
            $assignments = $this->recovery->plan($shifts, $doctors, $excludedDoctorIds, $dayOffRequests, $previousHistory, $this->ranker);

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
