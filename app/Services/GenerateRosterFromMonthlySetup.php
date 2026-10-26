<?php

namespace App\Services;

use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateRosterFromMonthlySetup
{
    public function __construct(
        private RosterStructureService $structure,
        private RosterAssignmentGenerator $generator,
        private RosterHistoryReadinessService $history,
    ) {}

    public function handle(int $year, int $month, User $admin): Roster
    {
        try {
            $this->structure->create($year, $month, $admin);
        } catch (UniqueConstraintViolationException $exception) {
            if (Roster::query()->where('year', $year)->where('month', $month)->doesntExist()) {
                throw $exception;
            }
        }

        try {
            return DB::transaction(function () use ($year, $month, $admin): Roster {
                $roster = Roster::query()
                    ->where('year', $year)
                    ->where('month', $month)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($roster->status === RosterStatus::Final || $roster->last_generated_at !== null) {
                    return $roster;
                }

                $readiness = $this->history->forMonth($year, $month);
                if (! $readiness['ready']) {
                    throw ValidationException::withMessages([
                        'roster' => $readiness['message'] ?? 'Previous-month history is not ready.',
                    ]);
                }

                $this->generator->generate($roster, $admin);

                return $roster->refresh();
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'roster' => 'Roster generation failed. No assignments were saved. Please try again.',
            ]);
        }
    }
}
