<?php

namespace App\Http\Controllers;

use App\Services\MonthlyWeekendPairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonthlyWeekendPairController extends Controller
{
    public function show(int $year, int $month, MonthlyWeekendPairService $pairs): JsonResponse
    {
        return response()->json($pairs->load($year, $month));
    }

    public function store(Request $request, int $year, int $month, MonthlyWeekendPairService $pairs): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'string', 'size:64'],
            'groups' => ['required', 'array:A,B'],
            'groups.A' => ['required', 'array:effective_saturday,doctor_ids'],
            'groups.B' => ['required', 'array:effective_saturday,doctor_ids'],
            'groups.*.effective_saturday' => ['required', 'date_format:Y-m-d'],
            'groups.*.doctor_ids' => ['required', 'array', 'min:6', 'max:7'],
            'groups.*.doctor_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return response()->json($pairs->confirm($year, $month, $data, $request->user()->id));
    }
}
