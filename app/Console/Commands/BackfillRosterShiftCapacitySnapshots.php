<?php

namespace App\Console\Commands;

use App\Services\RosterShiftCapacityBackfillService;
use Illuminate\Console\Command;
use JsonException;

/**
 * The JSON manifest contains a capacities list. Each row requires shift_id, year, month,
 * shift_date, shift_type, main_count, optional_count, and an evidence note. Running without
 * --apply reviews the full shift identity and proposed capacity without writing records.
 */
class BackfillRosterShiftCapacitySnapshots extends Command
{
    protected $signature = 'rosters:backfill-shift-capacities {manifest : JSON manifest with verified shift identities and capacities for every existing roster shift} {--apply : Apply the reviewed manifest; omit to only validate and preview}';

    protected $description = 'Review or apply a manifest with year, month, date, shift code, and verified capacities for existing roster shifts';

    public function handle(RosterShiftCapacityBackfillService $backfill): int
    {
        $contents = file_get_contents($this->argument('manifest'));
        if ($contents === false) {
            $this->error('The capacity manifest could not be read.');

            return self::FAILURE;
        }

        try {
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('The capacity manifest is not valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($manifest)) {
            $this->error('The capacity manifest must contain a JSON object.');

            return self::FAILURE;
        }

        $report = $this->option('apply')
            ? $backfill->apply($manifest)
            : $backfill->review($manifest);

        foreach ($report['rows'] as $row) {
            $snapshotState = $row['existing_snapshot_id'] === null ? 'to create' : 'already present and matching';
            $this->line(sprintf(
                '%04d-%02d shift %d %s %s: Main %d, Optional %d (%s). Evidence: %s',
                $row['year'],
                $row['month'],
                $row['shift_id'],
                $row['shift_date'],
                $row['shift_type'],
                $row['main_count'],
                $row['optional_count'],
                $snapshotState,
                $row['evidence'],
            ));
        }

        foreach ($report['errors'] as $error) {
            $this->error($error);
        }

        if (! $report['ready']) {
            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $this->info('Verified capacity snapshots were applied. Existing matching snapshots were preserved.');
        } else {
            $this->info('Manifest review passed. No records were changed. Re-run with --apply to save these snapshots.');
        }

        return self::SUCCESS;
    }
}
