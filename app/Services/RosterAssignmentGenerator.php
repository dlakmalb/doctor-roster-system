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
    public function __construct(private DoctorAssignmentEligibilityService $eligibility) {}

    public function generate(Roster $roster, User $admin): void
    {
        DB::transaction(function () use ($roster, $admin): void {
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);

            if ($roster->status !== RosterStatus::Draft) {
                throw ValidationException::withMessages(['roster' => 'Assignments can only be generated for a Draft roster.']);
            }

            $shifts = $roster->shifts()->with(['shiftType', 'assignments'])->get();
            $doctors = Doctor::query()->where('is_active', true)->orderBy('id')->get();
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
            $previousNights = DoctorMonthlyWorkload::query()
                ->where('year', $previousMonth->year)->where('month', $previousMonth->month)
                ->whereNotNull('most_recent_night_shift_at')->get()->keyBy('doctor_id');

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
                ['weekend_day', 'weekend_night'],
                ['weekday_night'],
                ['weekday_day'],
                ['weekday_evening'],
            ];

            foreach ($stages as $codes) {
                $this->fill($shifts->filter(fn (RosterShift $shift): bool => in_array($shift->shiftType->code, $codes, true)), RosterAssignmentRole::Main, $doctors, $excludedDoctorIds, $dayOffRequests, $previousNights, $assignedByDoctor);
            }

            $this->fill($shifts, RosterAssignmentRole::Optional, $doctors, $excludedDoctorIds, $dayOffRequests, $previousNights, $assignedByDoctor);

            $roster->update(['last_generated_at' => now(), 'updated_by' => $admin->id]);
        });
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, int>  $excludedDoctorIds
     * @param  Collection<int|string, Collection<int, DoctorRequest>>  $dayOffRequests
     * @param  Collection<int, DoctorMonthlyWorkload>  $previousNights
     * @param  Collection<int, Collection<int, RosterShift>>  $assignedByDoctor
     */
    private function fill(Collection $shifts, RosterAssignmentRole $role, Collection $doctors, Collection $excludedDoctorIds, Collection $dayOffRequests, Collection $previousNights, Collection $assignedByDoctor): void
    {
        $orderedShifts = $shifts->sortBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString().' '.$shift->shiftType->start_time);

        foreach ($orderedShifts as $shift) {
            $required = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;

            for ($slot = 1; $slot <= $required; $slot++) {
                if ($shift->assignments->contains(fn ($assignment): bool => $assignment->role === $role && $assignment->slot_number === $slot)) {
                    continue;
                }

                foreach ($doctors as $doctor) {
                    $history = $previousNights->get($doctor->id);
                    $conflicts = $this->eligibility->conflicts(
                        $doctor,
                        $shift,
                        $excludedDoctorIds->has($doctor->id),
                        $dayOffRequests->get($doctor->id, collect()),
                        $assignedByDoctor->get($doctor->id, collect()),
                        $history?->most_recent_night_shift_at,
                    );

                    if ($conflicts !== []) {
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
                    break;
                }
            }
        }
    }
}
