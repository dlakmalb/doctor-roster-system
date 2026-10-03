<?php

namespace App\Services;

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterShift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterAssignmentGenerator
{
    public function __construct(private DoctorAssignmentEligibilityService $eligibility, private RosterCandidateRanker $ranker) {}

    public function generate(Roster $roster, User $admin): void
    {
        DB::transaction(function () use ($roster, $admin): void {
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
            $this->ranker->initialize($shifts, $preferredRequests, $previousHistory, $firstDate);

            /** @var Collection<int, Collection<int, RosterShift>> $assignedByDoctor */
            $assignedByDoctor = collect();
            foreach ($shifts as $shift) {
                foreach ($shift->assignments as $assignment) {
                    $doctorShifts = $assignedByDoctor->get($assignment->doctor_id);
                    if ($doctorShifts === null) {
                        $assignedByDoctor->put($assignment->doctor_id, collect([$shift]));
                    } else {
                        $doctorShifts->push($shift);
                    }
                }
            }

            $stages = [
                $shifts->filter(fn (RosterShift $shift): bool => $this->ranker->weekendKey($shift) !== null),
                $shifts->filter(fn (RosterShift $shift): bool => $shift->shiftType->code === 'weekday_night' && $this->ranker->weekendKey($shift) === null),
                $shifts->filter(fn (RosterShift $shift): bool => $shift->shiftType->code === 'weekday_day'),
                $shifts->filter(fn (RosterShift $shift): bool => $shift->shiftType->code === 'weekday_evening'),
            ];

            foreach ($stages as $stage) {
                $this->fill($stage, RosterAssignmentRole::Main, $doctors, $excludedDoctorIds, $dayOffRequests, $previousHistory, $assignedByDoctor);
            }

            $this->fill($shifts, RosterAssignmentRole::Optional, $doctors, $excludedDoctorIds, $dayOffRequests, $previousHistory, $assignedByDoctor);

            $roster->update(['last_generated_at' => now(), 'updated_by' => $admin->id]);
        });
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, int>  $excludedDoctorIds
     * @param  Collection<int|string, Collection<int, DoctorRequest>>  $dayOffRequests
     * @param  Collection<int, DoctorMonthlyWorkload>  $previousHistory
     * @param  Collection<int, Collection<int, RosterShift>>  $assignedByDoctor
     */
    private function fill(Collection $shifts, RosterAssignmentRole $role, Collection $doctors, Collection $excludedDoctorIds, Collection $dayOffRequests, Collection $previousHistory, Collection $assignedByDoctor): void
    {
        $orderedShifts = $shifts->sortBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString().' '.$shift->shiftType->start_time);

        foreach ($orderedShifts as $shift) {
            $required = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;

            for ($slot = 1; $slot <= $required; $slot++) {
                if ($shift->assignments->contains(fn ($assignment): bool => $assignment->role === $role && $assignment->slot_number === $slot)) {
                    continue;
                }

                $eligible = $doctors->filter(function (Doctor $doctor) use ($shift, $excludedDoctorIds, $dayOffRequests, $previousHistory, $assignedByDoctor): bool {
                    $history = $previousHistory->get($doctor->id);
                    $conflicts = $this->eligibility->conflicts(
                        $doctor,
                        $shift,
                        $excludedDoctorIds->has($doctor->id),
                        $dayOffRequests->get($doctor->id, collect()),
                        $assignedByDoctor->get($doctor->id, collect()),
                        $history?->most_recent_night_shift_at,
                    );

                    return $conflicts === [];
                });
                $doctor = $this->ranker->select($eligible, $shift, $role, $assignedByDoctor);
                if ($doctor === null) {
                    continue;
                }

                $assignment = $shift->assignments()->create(['doctor_id' => $doctor->id, 'role' => $role, 'slot_number' => $slot]);
                $shift->assignments->push($assignment);
                $doctorShifts = $assignedByDoctor->get($doctor->id);
                if ($doctorShifts === null) {
                    $assignedByDoctor->put($doctor->id, collect([$shift]));
                } else {
                    $doctorShifts->push($shift);
                }
                $this->ranker->record($doctor->id, $shift, $role);
            }
        }
    }
}
