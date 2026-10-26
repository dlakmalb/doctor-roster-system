<?php

namespace App\Services;

use App\Models\RosterShift;
use App\Models\RosterShiftCapacitySnapshot;
use Illuminate\Support\Facades\DB;

class RosterShiftCapacityBackfillService
{
    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array{ready: bool, errors: list<string>, rows: list<array<string, int|string|null>>}
     */
    public function review(array $manifest): array
    {
        return $this->inspect($manifest, false);
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array{ready: bool, errors: list<string>, rows: list<array<string, int|string|null>>}
     */
    public function apply(array $manifest): array
    {
        return DB::transaction(function () use ($manifest): array {
            RosterShift::query()->orderBy('id')->lockForUpdate()->get(['id']);

            $report = $this->inspect($manifest, true);
            if (! $report['ready']) {
                return $report;
            }

            foreach ($report['rows'] as $row) {
                if ($row['existing_snapshot_id'] !== null) {
                    continue;
                }

                RosterShiftCapacitySnapshot::query()->create([
                    'roster_shift_id' => $row['shift_id'],
                    'main_count' => $row['main_count'],
                    'optional_count' => $row['optional_count'],
                ]);
            }

            return $report;
        });
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array{ready: bool, errors: list<string>, rows: list<array<string, int|string|null>>}
     */
    private function inspect(array $manifest, bool $forUpdate): array
    {
        $errors = [];
        $entries = $manifest['capacities'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            return ['ready' => false, 'errors' => ['Manifest must contain a capacities list.'], 'rows' => []];
        }

        $proposals = [];
        $seenShiftIds = [];
        foreach ($entries as $index => $entry) {
            $label = 'Manifest row '.($index + 1);
            if (! is_array($entry)) {
                $errors[] = "$label must be an object.";

                continue;
            }

            $shiftId = $entry['shift_id'] ?? null;
            $year = $entry['year'] ?? null;
            $month = $entry['month'] ?? null;
            $shiftDate = $entry['shift_date'] ?? null;
            $shiftType = $entry['shift_type'] ?? null;
            $mainCount = $entry['main_count'] ?? null;
            $optionalCount = $entry['optional_count'] ?? null;
            $evidence = $entry['evidence'] ?? null;
            if (! is_int($shiftId) || $shiftId < 1) {
                $errors[] = "$label must include a positive integer shift_id.";

                continue;
            }
            if (isset($seenShiftIds[$shiftId])) {
                $errors[] = "Shift $shiftId appears more than once in the manifest.";

                continue;
            }
            $seenShiftIds[$shiftId] = true;
            if (! is_int($year) || $year < 1 || $year > 9999
                || ! is_int($month) || $month < 1 || $month > 12
                || ! is_string($shiftDate) || strlen($shiftDate) !== 10
                || $shiftDate[4] !== '-' || $shiftDate[7] !== '-'
                || ! ctype_digit(substr($shiftDate, 0, 4))
                || ! ctype_digit(substr($shiftDate, 5, 2))
                || ! ctype_digit(substr($shiftDate, 8, 2))
                || ! checkdate((int) substr($shiftDate, 5, 2), (int) substr($shiftDate, 8, 2), (int) substr($shiftDate, 0, 4))
                || ! is_string($shiftType) || trim($shiftType) === '') {
                $errors[] = "$label must include a valid year, month, YYYY-MM-DD shift_date, and shift_type code.";

                continue;
            }
            if (! is_int($mainCount) || $mainCount < 0 || $mainCount > 255
                || ! is_int($optionalCount) || $optionalCount < 0 || $optionalCount > 255) {
                $errors[] = "$label must include Main and Optional counts from 0 through 255.";

                continue;
            }
            if (! is_string($evidence) || trim($evidence) === '') {
                $errors[] = "$label must include a non-empty evidence note.";

                continue;
            }

            $proposals[$shiftId] = [
                'main_count' => $mainCount,
                'optional_count' => $optionalCount,
                'evidence' => trim($evidence),
                'year' => $year,
                'month' => $month,
                'shift_date' => $shiftDate,
                'shift_type' => trim($shiftType),
            ];
        }

        $query = RosterShift::query()
            ->with(['roster:id,year,month', 'shiftType:id,code', 'assignments:id,roster_shift_id,role,slot_number', 'capacitySnapshot']);
        if ($forUpdate) {
            $query->orderBy('id')->lockForUpdate();
        }
        $shifts = $query->get();

        if ($shifts->isEmpty()) {
            $errors[] = 'No existing roster shifts were found; there is nothing to backfill.';
        }

        $rows = [];
        foreach ($shifts as $shift) {
            $proposal = $proposals[$shift->id] ?? null;
            if ($proposal === null) {
                $errors[] = "Roster shift {$shift->id} ({$shift->shift_date->toDateString()}) is missing from the manifest.";

                continue;
            }

            if ($proposal['year'] !== $shift->roster->year
                || $proposal['month'] !== $shift->roster->month
                || $proposal['shift_date'] !== $shift->shift_date->toDateString()
                || $proposal['shift_type'] !== $shift->shiftType->code) {
                $errors[] = sprintf(
                    'Roster shift %d identity mismatch: database has %04d-%02d %s %s, manifest has %04d-%02d %s %s.',
                    $shift->id,
                    $shift->roster->year,
                    $shift->roster->month,
                    $shift->shift_date->toDateString(),
                    $shift->shiftType->code,
                    $proposal['year'],
                    $proposal['month'],
                    $proposal['shift_date'],
                    $proposal['shift_type'],
                );
            }

            $snapshot = $shift->capacitySnapshot;
            if ($snapshot !== null
                && ($snapshot->main_count !== $proposal['main_count'] || $snapshot->optional_count !== $proposal['optional_count'])) {
                $errors[] = "Roster shift {$shift->id} already has a conflicting capacity snapshot; it will not be overwritten.";
            }

            foreach ($shift->assignments as $assignment) {
                $limit = $assignment->role->value === 'main' ? $proposal['main_count'] : $proposal['optional_count'];
                if ($assignment->slot_number > $limit) {
                    $errors[] = "Roster shift {$shift->id} has an assignment in {$assignment->role->value} slot {$assignment->slot_number}, outside the proposed capacity of $limit.";
                }
            }

            $rows[] = [
                'shift_id' => $shift->id,
                'year' => $shift->roster->year,
                'month' => $shift->roster->month,
                'shift_date' => $shift->shift_date->toDateString(),
                'shift_type' => $shift->shiftType->code,
                'main_count' => $proposal['main_count'],
                'optional_count' => $proposal['optional_count'],
                'evidence' => $proposal['evidence'],
                'existing_snapshot_id' => $snapshot?->id,
            ];
        }

        foreach (array_diff_key($proposals, $shifts->keyBy('id')->all()) as $shiftId => $_proposal) {
            $errors[] = "Manifest references unknown roster shift $shiftId.";
        }

        return ['ready' => $errors === [], 'errors' => $errors, 'rows' => $rows];
    }
}
