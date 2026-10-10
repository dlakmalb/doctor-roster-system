<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\Roster;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DoctorMonthlyParticipationService
{
    /**
     * @param  iterable<int|string>  $doctorIds
     * @return Collection<int, bool>
     */
    public function forMonth(int $year, int $month, iterable $doctorIds): Collection
    {
        $doctorIds = $this->normalizeDoctorIds($doctorIds);
        $rows = DoctorMonthlyParticipation::query()
            ->where('year', $year)
            ->where('month', $month)
            ->whereIn('doctor_id', $doctorIds)
            ->get()
            ->keyBy('doctor_id');

        if ($rows->count() !== $doctorIds->count()) {
            throw ValidationException::withMessages([
                'history' => "Complete participation history for {$this->label($year, $month)} before continuing.",
            ]);
        }

        return $doctorIds->mapWithKeys(fn (int $doctorId): array => [
            $doctorId => $rows->get($doctorId)->is_participating,
        ]);
    }

    /** @param iterable<int|string> $doctorIds
     * @return Collection<int, bool>
     */
    public function forRoster(Roster $roster, iterable $doctorIds): Collection
    {
        $this->assertRosterSnapshotIntegrity($roster);
        $doctorIds = $this->normalizeDoctorIds($doctorIds);
        $rows = DoctorMonthlyParticipation::query()
            ->where('roster_id', $roster->id)
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->whereIn('doctor_id', $doctorIds)
            ->get()
            ->keyBy('doctor_id');

        return $doctorIds->mapWithKeys(fn (int $doctorId): array => [
            $doctorId => $rows->get($doctorId)?->is_participating ?? false,
        ]);
    }

    /** @return Collection<int, int> */
    public function doctorIdsForRoster(Roster $roster): Collection
    {
        $this->assertRosterSnapshotIntegrity($roster);

        return DoctorMonthlyParticipation::query()
            ->where('roster_id', $roster->id)
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->orderBy('doctor_id')
            ->pluck('doctor_id')
            ->map(fn ($doctorId): int => (int) $doctorId)
            ->values();
    }

    /** @param iterable<int|string> $doctorIds */
    public function hasCompleteMonth(int $year, int $month, iterable $doctorIds): bool
    {
        $doctorIds = $this->normalizeDoctorIds($doctorIds);
        $count = DoctorMonthlyParticipation::query()
            ->where('year', $year)
            ->where('month', $month)
            ->whereIn('doctor_id', $doctorIds)
            ->count();

        return $count === $doctorIds->count();
    }

    public function saveBaseline(int $doctorId, int $year, int $month, bool $isParticipating): void
    {
        DoctorMonthlyParticipation::query()->updateOrCreate(
            ['doctor_id' => $doctorId, 'year' => $year, 'month' => $month],
            ['roster_id' => null, 'is_participating' => $isParticipating],
        );
    }

    /** @param Collection<int, Doctor> $participatingDoctors */
    public function snapshotRoster(Roster $roster, Collection $participatingDoctors): void
    {
        $participatingIds = $participatingDoctors->pluck('id')->map(fn (int|string $doctorId): int => (int) $doctorId)->all();
        $doctors = Doctor::query()->orderBy('id')->get(['id']);
        $timestamp = now();
        $rows = [];

        foreach ($doctors as $doctor) {
            $rows[] = [
                'doctor_id' => $doctor->id,
                'roster_id' => $roster->id,
                'year' => $roster->year,
                'month' => $roster->month,
                'is_participating' => in_array($doctor->id, $participatingIds, true),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DoctorMonthlyParticipation::query()->insertOrIgnore($chunk);
        }

        if ($roster->participation_snapshot_max_doctor_id === null) {
            $roster->update(['participation_snapshot_max_doctor_id' => $doctors->max('id')]);
        }
    }

    /** @param Collection<int, Doctor> $activeDoctors */
    public function ensureRosterSnapshot(Roster $roster, Collection $activeDoctors): void
    {
        $hasAnyRecords = DoctorMonthlyParticipation::query()
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->exists();

        if (! $hasAnyRecords) {
            $this->snapshotRoster($roster, $activeDoctors);
        }

        $this->assertRosterPopulationMatchesActiveDoctors($roster, $activeDoctors);
    }

    /** @param Collection<int, Doctor> $activeDoctors */
    public function assertRosterPopulationMatchesActiveDoctors(Roster $roster, Collection $activeDoctors): void
    {
        $this->assertRosterSnapshotIntegrity($roster);

        $rows = DoctorMonthlyParticipation::query()
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->get();
        $recordedParticipatingIds = $rows->where('is_participating', true)->pluck('doctor_id')->sort()->values();
        $activeDoctorIds = array_map('intval', $activeDoctors->modelKeys());
        sort($activeDoctorIds);

        if ($recordedParticipatingIds->all() !== $activeDoctorIds) {
            throw ValidationException::withMessages([
                'roster' => 'Active doctor statuses no longer match this Draft roster’s saved participation. Restore the active statuses to match the saved population before generating or regenerating.',
            ]);
        }
    }

    public function assertRosterSnapshotIntegrity(Roster $roster): void
    {
        $snapshotMaxDoctorId = $roster->participation_snapshot_max_doctor_id;
        if ($snapshotMaxDoctorId === null) {
            throw ValidationException::withMessages([
                'roster' => 'This roster is missing its participation snapshot boundary. Resolve the monthly participation records before continuing.',
            ]);
        }

        $doctorIds = Doctor::query()
            ->where('id', '<=', $snapshotMaxDoctorId)
            ->pluck('id')
            ->map(fn ($doctorId): int => (int) $doctorId)
            ->sort()
            ->values();
        $rows = DoctorMonthlyParticipation::query()
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->whereIn('doctor_id', $doctorIds)
            ->get();
        $hasUnexpectedDoctor = DoctorMonthlyParticipation::query()
            ->where('year', $roster->year)
            ->where('month', $roster->month)
            ->where('doctor_id', '>', $snapshotMaxDoctorId)
            ->exists();
        $recordedDoctorIds = $rows->pluck('doctor_id')->map(fn ($doctorId): int => (int) $doctorId)->sort()->values();

        if ($hasUnexpectedDoctor
            || $rows->count() !== $doctorIds->count()
            || $recordedDoctorIds->all() !== $doctorIds->all()
            || $rows->contains(fn (DoctorMonthlyParticipation $row): bool => (int) $row->roster_id !== $roster->id)) {
            throw ValidationException::withMessages([
                'roster' => 'This roster has incomplete or mismatched participation history. Resolve the monthly participation records before continuing.',
            ]);
        }
    }

    private function label(int $year, int $month): string
    {
        return now()->setDate($year, $month, 1)->format('F Y');
    }

    /** @param iterable<int|string> $doctorIds
     * @return Collection<int, int>
     */
    private function normalizeDoctorIds(iterable $doctorIds): Collection
    {
        return collect($doctorIds)
            ->map(fn (int|string $doctorId): int => (int) $doctorId)
            ->unique()
            ->values();
    }
}
