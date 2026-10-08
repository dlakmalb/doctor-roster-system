<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use App\Services\InitialWorkloadSetupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InitialWorkloadSetupController extends Controller
{
    public function show(int $year, int $month, InitialWorkloadSetupService $setup): Response
    {
        $setup->assertAllowedPeriod($year, $month);
        $existing = DoctorMonthlyWorkload::query()->where('year', $year)->where('month', $month)->get()->keyBy('doctor_id');
        $participations = DoctorMonthlyParticipation::query()->where('year', $year)->where('month', $month)->get()->keyBy('doctor_id');

        return Inertia::render('initial-workload-setup', [
            'month' => ['year' => $year, 'month' => $month, 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y')],
            'doctors' => Doctor::query()->orderBy('short_code')->get()->map(fn (Doctor $doctor): array => [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'short_code' => $doctor->short_code,
                'existing' => $existing->get($doctor->id),
                'participation_status' => match (true) {
                    $participations->has($doctor->id) && ! $participations->get($doctor->id)->is_participating => 'not_part_of_team',
                    $existing->get($doctor->id)?->is_month_excluded => 'full_month_excluded',
                    $participations->has($doctor->id) => 'participating',
                    default => '',
                },
            ]),
        ]);
    }

    public function save(int $year, int $month, Request $request, InitialWorkloadSetupService $setup): RedirectResponse
    {
        $input = $request->validate([
            'doctors' => ['required', 'array'],
            'doctors.*.doctor_id' => ['required', 'integer', 'exists:doctors,id'],
            'doctors.*.participation_status' => ['required', 'string', 'in:participating,full_month_excluded,not_part_of_team'],
            'doctors.*.actual_hours' => ['required', 'numeric', 'min:0'],
            'doctors.*.actual_night_duty_count' => ['required', 'integer', 'min:0'],
            'doctors.*.optional_assignment_count' => ['required', 'integer', 'min:0'],
            'doctors.*.worked_final_weekend' => ['required', 'boolean'],
            'doctors.*.most_recent_night_shift_at' => ['nullable', 'date'],
        ]);
        $setup->save($year, $month, $input['doctors']);

        return to_route('initial-workload.show', ['year' => $year, 'month' => $month]);
    }
}
