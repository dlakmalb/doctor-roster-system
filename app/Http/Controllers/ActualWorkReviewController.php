<?php

namespace App\Http\Controllers;

use App\Enums\ActualWorkExceptionType;
use App\Models\Doctor;
use App\Models\Roster;
use App\Models\User;
use App\Services\ActualWorkReviewService;
use App\Services\DoctorMonthlyWorkloadService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActualWorkReviewController extends Controller
{
    public function show(int $year, int $month, DoctorMonthlyWorkloadService $workloads): Response
    {
        $roster = $this->roster($year, $month);
        $roster->load(['shifts' => fn ($query) => $query->orderBy('shift_date'), 'shifts.shiftType', 'shifts.assignments.doctor', 'shifts.assignments.actualWorkExceptions.actualDoctor']);
        $shifts = [];
        $summary = ['main_absences' => 0, 'replacements' => 0, 'optionals_worked' => 0];
        foreach ($roster->shifts as $shift) {
            $assignments = [];
            foreach ($shift->assignments as $assignment) {
                $exception = $assignment->actualWorkExceptions->first();
                if ($exception !== null) {
                    $key = match ($exception->exception_type) {
                        ActualWorkExceptionType::MainAbsent => 'main_absences',
                        ActualWorkExceptionType::Replacement => 'replacements',
                        ActualWorkExceptionType::OptionalWorked => 'optionals_worked',
                    };
                    $summary[$key]++;
                }
                $actualDoctor = $exception?->actualDoctor;
                $assignments[] = [
                    'id' => $assignment->id,
                    'role' => $assignment->role->value,
                    'slot_number' => $assignment->slot_number,
                    'doctor' => ['id' => $assignment->doctor->id, 'name' => $assignment->doctor->name, 'short_code' => $assignment->doctor->short_code],
                    'exception' => $exception === null ? null : [
                        'id' => $exception->id,
                        'type' => $exception->exception_type->value,
                        'actual_doctor' => $actualDoctor === null ? null : [
                            'id' => $actualDoctor->id,
                            'name' => $actualDoctor->name,
                            'short_code' => $actualDoctor->short_code,
                        ],
                    ],
                ];
            }
            $shifts[] = [
                'id' => $shift->id,
                'date' => $shift->shift_date->toDateString(),
                'name' => $shift->shiftType->name,
                'duration_minutes' => $shift->shiftType->duration_minutes,
                'assignments' => $assignments,
            ];
        }

        return Inertia::render('actual-work-review', [
            'month' => ['year' => $year, 'month' => $month, 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y')],
            'status' => $roster->status->value,
            'confirmed_at' => $roster->actual_work_confirmed_at?->toDateTimeString(),
            'shifts' => $shifts,
            'doctors' => Doctor::query()->orderBy('short_code')->get(['id', 'name', 'short_code']),
            'summary' => $summary,
            'preview' => $workloads->preview($roster),
        ]);
    }

    public function save(int $year, int $month, int $assignment, Request $request, ActualWorkReviewService $review): RedirectResponse
    {
        $input = $request->validate([
            'exception_type' => ['required', Rule::enum(ActualWorkExceptionType::class)],
            'actual_doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
        ]);
        /** @var User $admin */
        $admin = $request->user();
        $review->save($this->roster($year, $month), $assignment, ActualWorkExceptionType::from($input['exception_type']), $input['actual_doctor_id'] ?? null, $admin);

        return to_route('rosters.actual-work.show', ['year' => $year, 'month' => $month]);
    }

    public function remove(int $year, int $month, int $exception, Request $request, ActualWorkReviewService $review): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $review->remove($this->roster($year, $month), $exception, $admin);

        return to_route('rosters.actual-work.show', ['year' => $year, 'month' => $month]);
    }

    public function confirm(int $year, int $month, Request $request, ActualWorkReviewService $review): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $review->confirm($this->roster($year, $month), $admin);

        return to_route('rosters.actual-work.show', ['year' => $year, 'month' => $month]);
    }

    private function roster(int $year, int $month): Roster
    {
        return Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
    }
}
