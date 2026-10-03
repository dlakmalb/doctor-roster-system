<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use App\Models\RosterShift;
use App\Models\User;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RosterController extends Controller
{
    public function store(int $year, int $month, Request $request, RosterStructureService $structure): RedirectResponse
    {
        /** @var User $creator */
        $creator = $request->user();

        try {
            $structure->create($year, $month, $creator);
        } catch (UniqueConstraintViolationException $exception) {
            if (! Roster::query()->where('year', $year)->where('month', $month)->exists()) {
                throw $exception;
            }
        }

        return to_route('rosters.show', ['year' => $year, 'month' => $month]);
    }

    public function show(int $year, int $month): Response
    {
        $roster = Roster::query()
            ->where('year', $year)
            ->where('month', $month)
            ->with(['shifts' => fn ($query) => $query->orderBy('shift_date'), 'shifts.shiftType'])
            ->firstOrFail();

        $days = $roster->shifts
            ->groupBy(fn (RosterShift $shift): string => $shift->shift_date->toDateString())
            ->map(function (Collection $shifts, string $date): array {
                $startDate = CarbonImmutable::parse($date);

                return [
                    'date' => $date,
                    'label' => $startDate->format('l, M j'),
                    'shifts' => $shifts->sortBy(fn (RosterShift $shift): string => $shift->shiftType->start_time)
                        ->map(fn (RosterShift $shift): array => [
                            'code' => $shift->shiftType->code,
                            'name' => $shift->shiftType->name,
                            'start_time' => substr($shift->shiftType->start_time, 0, 5),
                            'end_time' => substr($shift->shiftType->end_time, 0, 5),
                            'end_date_label' => $shift->shiftType->is_overnight ? $startDate->addDay()->format('M j') : null,
                            'main_count' => $shift->shiftType->main_count,
                            'optional_count' => $shift->shiftType->optional_count,
                        ])->values(),
                ];
            })->values();

        return Inertia::render('roster', [
            'month' => ['year' => $year, 'month' => $month, 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y')],
            'status' => $roster->status->value,
            'summary' => [
                'shifts' => $roster->shifts->count(),
                'main_positions' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->main_count),
                'optional_positions' => $roster->shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->optional_count),
            ],
            'days' => $days,
        ]);
    }
}
