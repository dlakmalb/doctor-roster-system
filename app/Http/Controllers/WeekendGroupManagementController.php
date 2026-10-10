<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\Roster;
use App\Models\User;
use App\Models\WeekendRotationConfiguration;
use App\Services\WeekendGroupRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WeekendGroupManagementController extends Controller
{
    public function show(int $year, int $month, WeekendGroupRotationService $rotation): Response
    {
        $monthStart = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $configuration = WeekendRotationConfiguration::query()->first();
        $weekends = collect();
        foreach ($rotation->weekendStartsForMonth($year, $month) as $saturday) {
            $expected = null;
            if ($configuration !== null && in_array($configuration->anchor_group, ['A', 'B'], true)) {
                $expected = $rotation->scheduledGroupFor($configuration, $saturday);
            }
            $weekends->push(['saturday' => $saturday->toDateString(), 'label' => $saturday->format('M j').'–'.$saturday->addDay()->format($saturday->month === $saturday->addDay()->month ? 'j' : 'M j'), 'group' => $expected]);
        }

        $doctors = Doctor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'short_code']);
        $membershipRows = DoctorWeekendGroupMembership::query()->with('doctor:id,name,short_code,is_active')
            ->whereDate('effective_from_saturday', '<=', $monthEnd->toDateString())
            ->orderBy('effective_from_saturday')->orderBy('doctor_id')->get();
        $memberships = $membershipRows->groupBy('doctor_id')->map(fn ($rows): DoctorWeekendGroupMembership => $rows->last())->values();
        $configurationStatus = $configuration === null ? null : $rotation->forCalendarMonth($year, $month, $doctors);

        return Inertia::render('weekend-groups', [
            'month' => ['year' => $year, 'month' => $month, 'label' => $monthStart->format('F Y')],
            'doctors' => $doctors,
            'memberships' => $memberships->map(fn (DoctorWeekendGroupMembership $membership): array => [
                'id' => $membership->id,
                'group' => $membership->group_code,
                'effective_from_saturday' => $membership->effective_from_saturday->toDateString(),
                'doctor' => ['id' => $membership->doctor->id, 'name' => $membership->doctor->name, 'short_code' => $membership->doctor->short_code, 'is_active' => $membership->doctor->is_active],
            ])->values(),
            'membershipHistory' => $membershipRows->map(fn (DoctorWeekendGroupMembership $membership): array => [
                'id' => $membership->id,
                'group' => $membership->group_code,
                'effective_from_saturday' => $membership->effective_from_saturday->toDateString(),
                'doctor' => ['id' => $membership->doctor->id, 'name' => $membership->doctor->name, 'short_code' => $membership->doctor->short_code, 'is_active' => $membership->doctor->is_active],
            ])->values(),
            'weekends' => $weekends,
            'anchor' => $configuration === null ? null : ['saturday' => $configuration->anchor_saturday->toDateString(), 'group' => $configuration->anchor_group],
            'configurationError' => $configurationStatus['error'] ?? null,
        ]);
    }

    public function store(Request $request, int $year, int $month, WeekendGroupRotationService $rotation): RedirectResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'],
            'group_code' => ['required', 'in:A,B'],
            'effective_from_saturday' => ['required', 'date'],
        ]);
        $doctor = Doctor::query()->whereKey($data['doctor_id'])->where('is_active', true)->first();
        if ($doctor === null) {
            throw ValidationException::withMessages(['doctor_id' => 'Select an active doctor.']);
        }
        $rotation->assertChangeAllowed($data['effective_from_saturday']);

        DB::transaction(function () use ($doctor, $data, $request): void {
            $existing = DoctorWeekendGroupMembership::query()
                ->where('doctor_id', $doctor->id)
                ->whereDate('effective_from_saturday', $data['effective_from_saturday'])
                ->first();
            if ($existing !== null) {
                if ($existing->group_code === $data['group_code']) {
                    return;
                }
                throw ValidationException::withMessages(['effective_from_saturday' => 'This doctor already has a different group recorded for that Saturday. Add a new future-effective change instead.']);
            }

            DoctorWeekendGroupMembership::query()->create([
                'doctor_id' => $doctor->id,
                'group_code' => $data['group_code'],
                'effective_from_saturday' => $data['effective_from_saturday'],
                'changed_by' => $request->user() instanceof User ? $request->user()->id : null,
            ]);
        });

        return back()->with('status', "{$doctor->name}'s Group {$data['group_code']} membership was saved.");
    }

    public function configure(Request $request, int $year, int $month): RedirectResponse
    {
        $data = $request->validate([
            'anchor_saturday' => ['required', 'date'],
            'anchor_group' => ['required', 'in:A,B'],
        ]);
        if (! CarbonImmutable::parse($data['anchor_saturday'])->isSaturday()) {
            throw ValidationException::withMessages(['anchor_saturday' => 'The rotation anchor must be a Saturday.']);
        }
        if (WeekendRotationConfiguration::query()->exists()) {
            throw ValidationException::withMessages(['anchor_saturday' => 'The rotation anchor is already configured and cannot be changed because it defines historical weekends.']);
        }
        if (Roster::query()->where('status', 'final')->exists()) {
            throw ValidationException::withMessages(['anchor_saturday' => 'Configure the anchor before finalizing any roster.']);
        }

        WeekendRotationConfiguration::query()->create(['singleton_key' => 'primary', ...$data]);

        return back()->with('status', 'Weekend rotation anchor configured.');
    }
}
