<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Services\MonthlyWeekendPairService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyWeekendPairController extends Controller
{
    public function show(int $year, int $month, MonthlyWeekendPairService $pairs): JsonResponse
    {
        return response()->json($pairs->load($year, $month));
    }

    public function store(Request $request, int $year, int $month, MonthlyWeekendPairService $pairs): JsonResponse
    {
        return response()->json($pairs->confirm($year, $month, $this->validatedInput($request), $request->user()->id));
    }

    public function page(int $year, int $month, MonthlyWeekendPairService $pairs): Response
    {
        $parameters = ['year' => $year, 'month' => $month];
        $state = $pairs->load($year, $month);
        $savedDoctorIds = collect($state['groups'])->flatMap(fn (array $group): array => $group['configuration']['doctor_ids'] ?? []);

        return Inertia::render('weekend-pairs', [
            'month' => [...$parameters, 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y')],
            'configuration' => $state,
            'savedDoctors' => $state['read_only']
                ? Doctor::query()->whereIn('id', $savedDoctorIds)->orderBy('id')->get(['id', 'name', 'short_code'])
                : [],
            'links' => [
                'show' => route('weekend-pairs.show', $parameters),
                'confirm' => route('weekend-pairs.confirm', $parameters),
                'monthlySetup' => route('monthly-setup.show', $parameters),
                'weekendGroups' => route('weekend-groups.show', $parameters),
                'roster' => route('rosters.show', $parameters),
            ],
        ]);
    }

    public function confirm(Request $request, int $year, int $month, MonthlyWeekendPairService $pairs): RedirectResponse
    {
        $pairs->confirm($year, $month, $this->validatedInput($request), $request->user()->id);

        return to_route('weekend-pairs.show', ['year' => $year, 'month' => $month])
            ->with('status', 'Weekend pair configuration confirmed.');
    }

    /** @return array<string, mixed> */
    private function validatedInput(Request $request): array
    {
        return $request->validate([
            'revision' => ['required', 'string', 'size:64'],
            'groups' => ['required', 'array:A,B'],
            'groups.A' => ['required', 'array:effective_saturday,doctor_ids'],
            'groups.B' => ['required', 'array:effective_saturday,doctor_ids'],
            'groups.*.effective_saturday' => ['required', 'date_format:Y-m-d'],
            'groups.*.doctor_ids' => ['required', 'array', 'min:6', 'max:7'],
            'groups.*.doctor_ids.*' => ['required', 'integer', 'distinct'],
        ]);
    }
}
