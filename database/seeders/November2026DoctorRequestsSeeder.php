<?php

namespace Database\Seeders;

use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\ShiftType;
use App\Services\RequestIntervalService;
use App\Services\WeekendPairCoordination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class November2026DoctorRequestsSeeder extends Seeder
{
    private const YEAR = 2026;

    private const MONTH = 11;

    /** @var list<array{doctor: string, date: string, type: DoctorRequestType, shift: string|null}> */
    private const REQUESTS = [
        ['doctor' => 'L', 'date' => '2026-11-01', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'L', 'date' => '2026-11-05', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'L', 'date' => '2026-11-06', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'L', 'date' => '2026-11-09', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'A', 'date' => '2026-11-02', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'A', 'date' => '2026-11-09', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'A', 'date' => '2026-11-16', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'I', 'date' => '2026-11-05', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'I', 'date' => '2026-11-06', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'R', 'date' => '2026-11-24', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'R', 'date' => '2026-11-25', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'R', 'date' => '2026-11-26', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'G', 'date' => '2026-11-12', 'type' => DoctorRequestType::PreferredWork, 'shift' => 'weekday_night'],
        ['doctor' => 'G', 'date' => '2026-11-19', 'type' => DoctorRequestType::PreferredWork, 'shift' => 'weekday_night'],
        ['doctor' => 'T', 'date' => '2026-11-05', 'type' => DoctorRequestType::PreferredWork, 'shift' => 'weekday_night'],
        ['doctor' => 'T', 'date' => '2026-11-12', 'type' => DoctorRequestType::PreferredWork, 'shift' => 'weekday_night'],
        ['doctor' => 'T', 'date' => '2026-11-19', 'type' => DoctorRequestType::PreferredWork, 'shift' => 'weekday_night'],
        ['doctor' => 'T', 'date' => '2026-11-26', 'type' => DoctorRequestType::PreferredWork, 'shift' => 'weekday_night'],
    ];

    /** @var list<string> */
    private const EXCLUSIONS = ['M', 'U'];

    public function run(WeekendPairCoordination $coordination, RequestIntervalService $intervals): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('November 2026 doctor request seeding is limited to local and testing environments.');
        }

        DB::transaction(function () use ($coordination, $intervals): void {
            $coordination->lock();

            $doctors = Doctor::query()->lockForUpdate()->get()->keyBy('short_code');
            $shiftTypes = ShiftType::query()->lockForUpdate()->get()->keyBy('code');
            $resolvedRequests = $this->resolveRequests($doctors, $shiftTypes);
            $resolvedExclusions = $this->resolveExclusions($doctors);
            $resolvedPreferences = $this->resolvePreferences($doctors, $shiftTypes);

            $existingRequests = DoctorRequest::query()
                ->whereBetween('request_date', ['2026-10-31', '2026-12-01'])
                ->with('shiftType')
                ->lockForUpdate()
                ->get();
            $existingExclusions = DoctorMonthlyExclusion::query()
                ->where('year', self::YEAR)
                ->where('month', self::MONTH)
                ->lockForUpdate()
                ->get();
            $existingPreferences = DoctorMonthlyWeekdayPreference::query()
                ->where('year', self::YEAR)
                ->where('month', self::MONTH)
                ->lockForUpdate()
                ->get();

            $this->assertNoConflictingExclusions($resolvedRequests, $resolvedPreferences, $existingExclusions);
            $missingRequests = $this->missingRequests($resolvedRequests, $existingRequests, $shiftTypes, $intervals);
            $missingExclusions = $this->missingExclusions($resolvedExclusions, $existingExclusions);
            $missingPreferences = $this->missingPreferences($resolvedPreferences, $existingPreferences);

            if ($missingRequests === [] && $missingExclusions === [] && $missingPreferences === []) {
                return;
            }

            $roster = Roster::query()
                ->where('year', self::YEAR)
                ->where('month', self::MONTH)
                ->lockForUpdate()
                ->first();
            if ($roster?->status === RosterStatus::Final) {
                $this->fail('November 2026 has a Final roster; refusing to change its request, exclusion, or preference data.');
            }
            if ($roster !== null && ($roster->last_generated_at !== null || RosterAssignment::query()
                ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))
                ->exists())) {
                $this->fail('November 2026 has generated or assigned Draft work; refusing to add constraints that could invalidate assignments.');
            }

            foreach ($missingRequests as $request) {
                DoctorRequest::query()->create($request);
            }
            foreach ($missingExclusions as $exclusion) {
                DoctorMonthlyExclusion::query()->create($exclusion);
            }
            foreach ($missingPreferences as $preference) {
                DoctorMonthlyWeekdayPreference::query()->create($preference);
            }
        }, 3);
    }

    /** @param Collection<int, Doctor> $doctors
     * @param Collection<int, ShiftType> $shiftTypes
     * @return list<array{doctor: string, doctor_id: int, request_date: string, request_type: DoctorRequestType, shift_type_id: int|null}>
     */
    private function resolveRequests(Collection $doctors, Collection $shiftTypes): array
    {
        return array_map(function (array $request) use ($doctors, $shiftTypes): array {
            $doctor = $this->activeDoctor($doctors, $request['doctor']);
            $shiftType = $request['shift'] === null ? null : $shiftTypes->get($request['shift']);
            if ($request['shift'] !== null && (! $shiftType instanceof ShiftType || ! $shiftType->is_active)) {
                $this->fail("Required active shift type {$request['shift']} does not exist.");
            }

            return [
                'doctor' => $request['doctor'],
                'doctor_id' => $doctor->id,
                'request_date' => $request['date'],
                'request_type' => $request['type'],
                'shift_type_id' => $shiftType?->id,
            ];
        }, self::REQUESTS);
    }

    /** @param Collection<int, Doctor> $doctors
     * @return list<array{doctor_id: int, year: int, month: int}>
     */
    private function resolveExclusions(Collection $doctors): array
    {
        return array_map(fn (string $shortCode): array => [
            'doctor_id' => $this->activeDoctor($doctors, $shortCode)->id,
            'year' => self::YEAR,
            'month' => self::MONTH,
        ], self::EXCLUSIONS);
    }

    /** @param Collection<int, Doctor> $doctors
     * @param  Collection<int, ShiftType>  $shiftTypes
     * @return list<array{doctor_id: int, year: int, month: int, shift_type_id: int, weekday: int}>
     */
    private function resolvePreferences(Collection $doctors, Collection $shiftTypes): array
    {
        $doctor = $this->activeDoctor($doctors, 'T');
        $shiftType = $shiftTypes->get('weekday_night');
        if (! $shiftType instanceof ShiftType || ! $shiftType->is_active) {
            $this->fail('Required active shift type weekday_night does not exist.');
        }

        return [[
            'doctor_id' => $doctor->id,
            'year' => self::YEAR,
            'month' => self::MONTH,
            'shift_type_id' => $shiftType->id,
            'weekday' => 4,
        ]];
    }

    /** @param Collection<int, Doctor> $doctors */
    private function activeDoctor(Collection $doctors, string $shortCode): Doctor
    {
        $doctor = $doctors->get($shortCode);
        if (! $doctor instanceof Doctor || ! $doctor->is_active) {
            $this->fail("Required active doctor {$shortCode} does not exist.");
        }

        return $doctor;
    }

    /** @param list<array{doctor: string, doctor_id: int, request_date: string, request_type: DoctorRequestType, shift_type_id: int|null}> $expected
     * @param  Collection<int, DoctorRequest>  $existing
     * @return list<array{doctor_id: int, request_type: DoctorRequestType, request_date: string, shift_type_id: int|null}>
     */
    private function missingRequests(array $expected, Collection $existing, Collection $shiftTypes, RequestIntervalService $intervals): array
    {
        $missing = [];
        foreach ($expected as $request) {
            $shiftType = $request['shift_type_id'] === null
                ? null
                : $shiftTypes->first(fn (ShiftType $shift): bool => $shift->id === $request['shift_type_id']);
            $requestedInterval = $intervals->forDate(CarbonImmutable::parse($request['request_date']), $shiftType);
            $matching = $existing->filter(fn (DoctorRequest $candidate): bool => $candidate->doctor_id === $request['doctor_id']
                && $candidate->request_date->toDateString() === $request['request_date']
                && $candidate->request_type === $request['request_type']
                && $candidate->shift_type_id === $request['shift_type_id']);

            if ($matching->count() > 1) {
                $this->fail("Duplicate November requests already exist for doctor {$request['doctor']} on {$request['request_date']}.");
            }

            foreach ($existing->where('doctor_id', $request['doctor_id']) as $candidate) {
                if ($intervals->overlaps($requestedInterval, $intervals->forRequest($candidate)) && ! $matching->contains(fn (DoctorRequest $match): bool => $match->is($candidate))) {
                    $this->fail("Existing request overlaps the expected November request for doctor {$request['doctor']} on {$request['request_date']}.");
                }
            }

            if ($matching->isEmpty()) {
                $missing[] = [
                    'doctor_id' => $request['doctor_id'],
                    'request_type' => $request['request_type'],
                    'request_date' => $request['request_date'],
                    'shift_type_id' => $request['shift_type_id'],
                ];
            }
        }

        return $missing;
    }

    /** @param list<array{doctor_id: int, year: int, month: int}> $expected
     * @param  Collection<int, DoctorMonthlyExclusion>  $existing
     * @return list<array{doctor_id: int, year: int, month: int}>
     */
    private function missingExclusions(array $expected, Collection $existing): array
    {
        return $this->missingRows($expected, $existing, []);
    }

    /** @param list<array{doctor: string, doctor_id: int, request_date: string, request_type: DoctorRequestType, shift_type_id: int|null}> $requests
     * @param  list<array{doctor_id: int, year: int, month: int, shift_type_id: int, weekday: int}>  $preferences
     * @param  Collection<int, DoctorMonthlyExclusion>  $existing
     */
    private function assertNoConflictingExclusions(array $requests, array $preferences, Collection $existing): void
    {
        $seedDoctorIds = collect([...$requests, ...$preferences])->pluck('doctor_id');
        if ($existing->contains(fn (DoctorMonthlyExclusion $exclusion): bool => $seedDoctorIds->contains($exclusion->doctor_id))) {
            $this->fail('A November request or preference doctor already has a monthly exclusion; resolve it before seeding.');
        }
    }

    /** @param list<array{doctor_id: int, year: int, month: int, shift_type_id: int, weekday: int}> $expected
     * @param  Collection<int, DoctorMonthlyWeekdayPreference>  $existing
     * @return list<array{doctor_id: int, year: int, month: int, shift_type_id: int, weekday: int}>
     */
    private function missingPreferences(array $expected, Collection $existing): array
    {
        return $this->missingRows($expected, $existing, ['shift_type_id', 'weekday']);
    }

    /** @param list<array<string, int>> $expected
     * @param  Collection<int, Model>  $existing
     * @param  list<string>  $additionalKeys
     * @return list<array<string, int>>
     */
    private function missingRows(array $expected, Collection $existing, array $additionalKeys): array
    {
        $missing = [];
        foreach ($expected as $row) {
            $matching = $existing->first(fn (Model $candidate): bool => $candidate->doctor_id === $row['doctor_id']
                && $candidate->year === $row['year']
                && $candidate->month === $row['month']
                && collect($additionalKeys)->every(fn (string $key): bool => $candidate->{$key} === ($row[$key] ?? null)));
            if ($matching === null) {
                $missing[] = $row;
            }
        }

        return $missing;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['november_2026_requests' => $message]);
    }
}
