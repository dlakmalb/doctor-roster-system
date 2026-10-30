<?php

namespace App\Http\Controllers;

use App\Services\MonthlyShiftCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MonthlyShiftCapacityController extends Controller
{
    public function store(int $year, int $month, Request $request, MonthlyShiftCapacityService $capacities): RedirectResponse
    {
        $validated = $request->validate([
            'capacities' => ['required', 'array', 'size:5'],
            'capacities.*' => ['required', 'array:shift_type,main_count,optional_count'],
            'capacities.*.shift_type' => ['required', 'string', 'distinct:strict'],
            'capacities.*.main_count' => ['required', 'integer', 'between:0,255'],
            'capacities.*.optional_count' => ['required', 'integer', 'between:0,255'],
        ]);

        $validatedCapacities = array_map(fn (array $capacity): array => [
            'shift_type' => $capacity['shift_type'],
            'main_count' => (int) $capacity['main_count'],
            'optional_count' => (int) $capacity['optional_count'],
        ], $validated['capacities']);

        $capacities->saveAll($year, $month, $validatedCapacities);

        return to_route('monthly-setup.show', compact('year', 'month'))
            ->with('status', 'Shift capacity configuration saved successfully.');
    }
}
