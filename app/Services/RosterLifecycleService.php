<?php

namespace App\Services;

use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterLifecycleService
{
    public function __construct(private RosterDraftValidationService $validation) {}

    /**
     * @return array{status: 'finalized'|'errors'|'confirmation_required'|'stale_confirmation', errors: list<array{severity: string, message: string, target: string}>, warnings: list<array{severity: string, message: string, target: string}>, warning_signature: string|null}
     */
    public function finalize(Roster $roster, User $admin, ?string $confirmedSignature): array
    {
        $result = DB::transaction(function () use ($roster, $admin, $confirmedSignature): array {
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);
            if ($roster->status !== RosterStatus::Draft) {
                throw ValidationException::withMessages(['roster' => 'Only a Draft roster can be finalized.']);
            }

            $validation = $this->validation->validate($roster);
            $errors = array_values(array_filter($validation, fn (array $item): bool => $item['severity'] === 'Error'));
            $warnings = array_values(array_filter($validation, fn (array $item): bool => $item['severity'] === 'Warning'));
            $signature = $warnings === [] ? null : $this->warningSignature($roster, $warnings);

            if ($errors !== []) {
                return ['status' => 'errors', 'errors' => $errors, 'warnings' => $warnings, 'warning_signature' => $signature];
            }
            if ($signature !== null && ($confirmedSignature === null || ! hash_equals($signature, $confirmedSignature))) {
                return [
                    'status' => $confirmedSignature === null ? 'confirmation_required' : 'stale_confirmation',
                    'errors' => [],
                    'warnings' => $warnings,
                    'warning_signature' => $signature,
                ];
            }

            $roster->update([
                'status' => RosterStatus::Final,
                'finalized_at' => now(),
                'finalized_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            return ['status' => 'finalized', 'errors' => [], 'warnings' => $warnings, 'warning_signature' => $signature];
        });

        if ($result['status'] === 'finalized') {
            $this->clearUndo($roster);
        }

        return $result;
    }

    public function canReopen(Roster $roster): bool
    {
        return $roster->actual_work_confirmed_at === null
            && ! ActualWorkException::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))->exists();
    }

    public function reopen(Roster $roster, User $admin): void
    {
        DB::transaction(function () use ($roster, $admin): void {
            $roster = Roster::query()->lockForUpdate()->findOrFail($roster->id);
            if ($roster->status !== RosterStatus::Final) {
                throw ValidationException::withMessages(['roster' => 'Only a Final roster can be reopened.']);
            }
            if (! $this->canReopen($roster)) {
                throw ValidationException::withMessages(['roster' => 'This roster cannot be reopened because actual-work review has already started. Correct historical actual work from the Previous Month Review screen instead.']);
            }

            $roster->update([
                'status' => RosterStatus::Draft,
                'reopened_at' => now(),
                'reopened_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        });

        $this->clearUndo($roster);
    }

    /** @param list<array{severity: string, message: string, target: string}> $warnings */
    private function warningSignature(Roster $roster, array $warnings): string
    {
        $normalized = array_map(fn (array $warning): string => implode('|', [$warning['severity'], $warning['target'], $warning['message']]), $warnings);
        sort($normalized, SORT_STRING);

        return hash_hmac('sha256', json_encode([$roster->id, $normalized], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function clearUndo(Roster $roster): void
    {
        $undo = session()->get(RosterManualEditService::UNDO_KEY);
        if (is_array($undo) && ($undo['roster_id'] ?? null) === $roster->id) {
            session()->forget(RosterManualEditService::UNDO_KEY);
        }
    }
}
