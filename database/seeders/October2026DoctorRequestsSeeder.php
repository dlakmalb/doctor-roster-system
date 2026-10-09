<?php

namespace Database\Seeders;

use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyShiftRestriction;
use App\Models\DoctorMonthlyWeekdayPreference;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Services\RequestIntervalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class October2026DoctorRequestsSeeder extends Seeder
{
    private const YEAR = 2026;

    private const MONTH = 10;

    /** @var list<array{doctor: string, date: string, type: DoctorRequestType, shift: string|null}> */
    private const REQUESTS = [
        ['doctor' => 'B', 'date' => '2026-10-01', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'B', 'date' => '2026-10-09', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'I', 'date' => '2026-10-07', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'I', 'date' => '2026-10-08', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'I', 'date' => '2026-10-09', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'M', 'date' => '2026-10-06', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'M', 'date' => '2026-10-07', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'M', 'date' => '2026-10-08', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'U', 'date' => '2026-10-02', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'U', 'date' => '2026-10-17', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'U', 'date' => '2026-10-18', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'G', 'date' => '2026-10-15', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'S', 'date' => '2026-10-02', 'type' => DoctorRequestType::DayOff, 'shift' => 'weekday_night'],
        ['doctor' => 'S', 'date' => '2026-10-10', 'type' => DoctorRequestType::DayOff, 'shift' => 'weekend_night'],
        ['doctor' => 'S', 'date' => '2026-10-09', 'type' => DoctorRequestType::PreferredWork, 'shift' => 'weekday_night'],
        ['doctor' => 'K', 'date' => '2026-10-01', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'K', 'date' => '2026-10-02', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'L', 'date' => '2026-10-09', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'L', 'date' => '2026-10-12', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'L', 'date' => '2026-10-13', 'type' => DoctorRequestType::DayOff, 'shift' => null],
        ['doctor' => 'T', 'date' => '2026-10-12', 'type' => DoctorRequestType::DayOff, 'shift' => null],
    ];

    /** @var list<array{doctor: string, shift: string}> */
    private const RESTRICTIONS = [
        ['doctor' => 'N', 'shift' => 'weekday_evening'],
        ['doctor' => 'N', 'shift' => 'weekday_night'],
        ['doctor' => 'N', 'shift' => 'weekend_night'],
    ];

    /** @var list<array{doctor: string, shift: string, weekday: int}> */
    private const WEEKDAY_PREFERENCES = [
        ['doctor' => 'T', 'shift' => 'weekday_night', 'weekday' => 4],
        ['doctor' => 'T', 'shift' => 'weekend_night', 'weekday' => 7],
        ['doctor' => 'G', 'shift' => 'weekday_evening', 'weekday' => 4],
        ['doctor' => 'G', 'shift' => 'weekday_night', 'weekday' => 4],
    ];

    public function run(RequestIntervalService $intervals): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('October 2026 doctor request seeding is limited to local and testing environments.');
        }

        DB::transaction(function () use ($intervals): void {
            $doctors = Doctor::query()->lockForUpdate()->get()->keyBy('short_code');
            $shiftTypes = ShiftType::query()->lockForUpdate()->get()->keyBy('code');
            $resolvedRequests = $this->resolveRequests($doctors, $shiftTypes);
            $resolvedRestrictions = $this->resolveRestrictions($doctors, $shiftTypes);
            $resolvedPreferences = $this->resolvePreferences($doctors, $shiftTypes);

            $this->assertDefinitions($resolvedRequests, $resolvedRestrictions, $resolvedPreferences, $intervals);
            $this->assertNoMonthlyExclusions($resolvedRequests, $resolvedRestrictions, $resolvedPreferences);

            $existingRequests = DoctorRequest::query()->whereBetween('request_date', ['2026-09-30', '2026-11-01'])->with('shiftType')->lockForUpdate()->get();
            $existingRestrictions = DoctorMonthlyShiftRestriction::query()->where('year', self::YEAR)->where('month', self::MONTH)->lockForUpdate()->get();
            $existingPreferences = DoctorMonthlyWeekdayPreference::query()->where('year', self::YEAR)->where('month', self::MONTH)->lockForUpdate()->get();
            $nuwan = $doctors->get('N');
            $allowedDayShiftIds = $shiftTypes->only(['weekday_day', 'weekend_day'])->pluck('id')->all();
            if ($existingRestrictions->contains(fn (DoctorMonthlyShiftRestriction $restriction): bool => $restriction->doctor_id === $nuwan->id
                && in_array($restriction->shift_type_id, $allowedDayShiftIds, true))) {
                $this->fail('Dr Nuwan has an existing October Day shift restriction that conflicts with the Day-only requirement.');
            }
            $this->assertNoRestrictedPreferences($resolvedPreferences, $existingRestrictions, $doctors, $shiftTypes);

            $missingRequests = $this->missingRequests($resolvedRequests, $existingRequests, $intervals);
            $missingRestrictions = $this->missingRows($resolvedRestrictions, $existingRestrictions, 'shift_type_id');
            $missingPreferences = $this->missingRows($resolvedPreferences, $existingPreferences, 'shift_type_id', ['weekday']);

            if ($missingRequests === [] && $missingRestrictions === [] && $missingPreferences === []) {
                return;
            }

            $roster = Roster::query()->where('year', self::YEAR)->where('month', self::MONTH)->lockForUpdate()->first();
            if ($roster?->status === RosterStatus::Final) {
                $this->fail('October 2026 has a Final roster; refusing to change its request or preference data.');
            }
            if ($roster !== null && ($roster->last_generated_at !== null || RosterAssignment::query()
                ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $roster->id))->exists())) {
                $this->fail('October 2026 has generated or assigned Draft work; refusing to add constraints that could invalidate assignments.');
            }

            foreach ($missingRequests as $request) {
                DoctorRequest::query()->create($request);
            }
            foreach ($missingRestrictions as $restriction) {
                DoctorMonthlyShiftRestriction::query()->create($restriction);
            }
            foreach ($missingPreferences as $preference) {
                DoctorMonthlyWeekdayPreference::query()->create($preference);
            }
        }, 3);
    }

    /** @return list<array{doctor: string, date: string, type: DoctorRequestType, shift: string|null}> */
    private function resolveRequests(Collection $doctors, Collection $shiftTypes): array
    {
        return array_map(function (array $request) use ($doctors, $shiftTypes): array {
            $doctor = $doctors->get($request['doctor']);
            if (! $doctor instanceof Doctor) {
                $this->fail("Required doctor {$request['doctor']} does not exist.");
            }
            if (! $doctor->is_active) {
                $this->fail("Required doctor {$request['doctor']} is inactive for October 2026.");
            }
            $shift = $request['shift'] === null ? null : $this->shift($shiftTypes, $request['shift']);

            return [
                'doctor_id' => $doctor->id,
                'doctor' => $request['doctor'],
                'request_type' => $request['type'],
                'request_date' => $request['date'],
                'shift_type_id' => $shift?->id,
                'shift' => $request['shift'],
            ];
        }, self::REQUESTS);
    }

    /** @return list<array{doctor_id: int, year: int, month: int, shift_type_id: int}> */
    private function resolveRestrictions(Collection $doctors, Collection $shiftTypes): array
    {
        return array_map(function (array $restriction) use ($doctors, $shiftTypes): array {
            $doctor = $doctors->get($restriction['doctor']);
            if (! $doctor instanceof Doctor || ! $doctor->is_active) {
                $this->fail("Required active doctor {$restriction['doctor']} does not exist.");
            }

            return ['doctor_id' => $doctor->id, 'year' => self::YEAR, 'month' => self::MONTH, 'shift_type_id' => $this->shift($shiftTypes, $restriction['shift'])->id];
        }, self::RESTRICTIONS);
    }

    /** @return list<array{doctor_id: int, year: int, month: int, shift_type_id: int, weekday: int}> */
    private function resolvePreferences(Collection $doctors, Collection $shiftTypes): array
    {
        return array_map(function (array $preference) use ($doctors, $shiftTypes): array {
            $doctor = $doctors->get($preference['doctor']);
            if (! $doctor instanceof Doctor || ! $doctor->is_active) {
                $this->fail("Required active doctor {$preference['doctor']} does not exist.");
            }

            return ['doctor_id' => $doctor->id, 'year' => self::YEAR, 'month' => self::MONTH, 'shift_type_id' => $this->shift($shiftTypes, $preference['shift'])->id, 'weekday' => $preference['weekday']];
        }, self::WEEKDAY_PREFERENCES);
    }

    private function shift(Collection $shiftTypes, string $code): ShiftType
    {
        $shift = $shiftTypes->get($code);
        if (! $shift instanceof ShiftType || ! $shift->is_active) {
            $this->fail("Required active shift type {$code} does not exist.");
        }

        return $shift;
    }

    /** @param list<array<string, mixed>> $requests
     * @param list<array<string, mixed>> $restrictions
     * @param list<array<string, mixed>> $preferences
     */
    private function assertDefinitions(array $requests, array $restrictions, array $preferences, RequestIntervalService $intervals): void
    {
        if (count($requests) !== 21 || count(array_filter($requests, fn (array $row): bool => $row['request_type'] === DoctorRequestType::DayOff)) !== 20
            || count($restrictions) !== 3 || count($preferences) !== 4) {
            $this->fail('The October request dataset does not match the required 20 Day-Off, 1 Preferred Work, 3 restriction, and 4 weekday preference counts.');
        }

        $requestKeys = [];
        $requestIntervals = [];
        foreach ($requests as $request) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $request['request_date']);
            if ($date === false || $date->year !== self::YEAR || $date->month !== self::MONTH) {
                $this->fail("Request date {$request['request_date']} is not in October 2026.");
            }
            $shift = $request['shift_type_id'] === null ? null : ShiftType::query()->find($request['shift_type_id']);
            $validCodes = $date->isWeekend() ? ['weekend_day', 'weekend_night'] : ['weekday_day', 'weekday_evening', 'weekday_night'];
            if ($shift !== null && ! in_array($shift->code, $validCodes, true)) {
                $this->fail("Shift {$shift->code} is incompatible with {$request['request_date']}.");
            }
            if ($request['request_type'] === DoctorRequestType::PreferredWork && $shift === null) {
                $this->fail('Date-specific Preferred Work requests must target a shift.');
            }
            $key = implode('|', [$request['doctor_id'], $request['request_date'], $request['request_type']->value, $request['shift_type_id'] ?? 'null']);
            if (isset($requestKeys[$key])) {
                $this->fail("Duplicate date-specific request definition for doctor {$request['doctor']} on {$request['request_date']}.");
            }
            $requestKeys[$key] = true;
            $requestIntervals[] = ['doctor_id' => $request['doctor_id'], 'type' => $request['request_type'], 'interval' => $intervals->forDate($date, $shift)];
        }
        for ($left = 0; $left < count($requestIntervals); $left++) {
            for ($right = $left + 1; $right < count($requestIntervals); $right++) {
                $first = $requestIntervals[$left];
                $second = $requestIntervals[$right];
                if ($first['doctor_id'] === $second['doctor_id'] && $first['type'] !== $second['type'] && $intervals->overlaps($first['interval'], $second['interval'])) {
                    $this->fail('The October request dataset contains overlapping Day-Off and Preferred Work intervals.');
                }
            }
        }
        foreach ($preferences as $preference) {
            $shift = ShiftType::query()->find($preference['shift_type_id']);
            $isWeekendWeekday = in_array($preference['weekday'], [6, 7], true);
            if ($preference['weekday'] < 1 || $preference['weekday'] > 7 || str_starts_with($shift->code, 'weekend_') !== $isWeekendWeekday) {
                $this->fail("Weekday {$preference['weekday']} is incompatible with shift {$shift->code}.");
            }
        }

        $restrictionKeys = array_map(fn (array $row): string => $row['doctor_id'].'|'.$row['shift_type_id'], $restrictions);
        if (count(array_unique($restrictionKeys)) !== count($restrictionKeys)) {
            $this->fail('The October dataset contains duplicate monthly shift restrictions.');
        }
        $preferenceKeys = array_map(fn (array $row): string => $row['doctor_id'].'|'.$row['shift_type_id'].'|'.$row['weekday'], $preferences);
        if (count(array_unique($preferenceKeys)) !== count($preferenceKeys)) {
            $this->fail('The October dataset contains duplicate monthly weekday preferences.');
        }
    }

    /** @param list<array<string, mixed>> $requests
     * @param list<array<string, mixed>> $restrictions
     * @param list<array<string, mixed>> $preferences
     */
    private function assertNoMonthlyExclusions(array $requests, array $restrictions, array $preferences): void
    {
        $doctorIds = collect([...$requests, ...$restrictions, ...$preferences])->pluck('doctor_id')->unique();
        if (DoctorMonthlyExclusion::query()->where('year', self::YEAR)->where('month', self::MONTH)->whereIn('doctor_id', $doctorIds)->exists()) {
            $this->fail('An October seed doctor has a monthly exclusion; refusing to add conflicting requests or preferences.');
        }
    }

    /** @param list<array{doctor_id: int, year: int, month: int, shift_type_id: int, weekday: int}> $preferences
     * @param Collection<int, DoctorMonthlyShiftRestriction> $restrictions
     * @param Collection<string, Doctor> $doctors
     * @param Collection<string, ShiftType> $shiftTypes
     */
    private function assertNoRestrictedPreferences(array $preferences, Collection $restrictions, Collection $doctors, Collection $shiftTypes): void
    {
        foreach ($preferences as $preference) {
            $restriction = $restrictions->first(fn (DoctorMonthlyShiftRestriction $candidate): bool => $candidate->doctor_id === $preference['doctor_id']
                && $candidate->shift_type_id === $preference['shift_type_id']);
            if ($restriction === null) {
                continue;
            }

            $doctor = $doctors->first(fn (Doctor $candidate): bool => $candidate->id === $preference['doctor_id']);
            $shift = $shiftTypes->first(fn (ShiftType $candidate): bool => $candidate->id === $preference['shift_type_id']);
            $weekday = CarbonImmutable::createFromDate(2026, 10, 5)->addDays($preference['weekday'] - 1)->englishDayOfWeek;
            $this->fail("Dr {$doctor->short_code} has an October restriction for {$shift->code}, which conflicts with the {$weekday} preference. Remove or resolve that restriction before seeding.");
        }
    }

    /** @param list<array<string, mixed>> $expected
     * @param Collection<int, Model> $existing
     * @param list<string> $extraKeys
     * @return list<array<string, mixed>>
     */
    private function missingRows(array $expected, Collection $existing, string $shiftKey, array $extraKeys = []): array
    {
        $missing = [];
        foreach ($expected as $row) {
            $matching = $existing->first(fn (Model $candidate): bool => $candidate->doctor_id === $row['doctor_id'] && $candidate->{$shiftKey} === $row[$shiftKey]
                && collect($extraKeys)->every(fn (string $key): bool => $candidate->{$key} === $row[$key]));
            if ($matching === null) {
                $missing[] = $row;
            }
        }

        return $missing;
    }

    /** @param list<array<string, mixed>> $expected
     * @param Collection<int, DoctorRequest> $existing
     * @return list<array<string, mixed>>
     */
    private function missingRequests(array $expected, Collection $existing, RequestIntervalService $intervals): array
    {
        $missing = [];
        $expectedKeys = [];
        foreach ($expected as $request) {
            $key = implode('|', [$request['doctor_id'], $request['request_date'], $request['request_type']->value, $request['shift_type_id'] ?? 'null']);
            $expectedKeys[$key] = true;
            $equivalent = $existing->first(fn (DoctorRequest $candidate): bool => $candidate->doctor_id === $request['doctor_id']
                && $candidate->request_date->toDateString() === $request['request_date'] && $candidate->request_type === $request['request_type']
                && $candidate->shift_type_id === $request['shift_type_id']);
            $shift = $request['shift_type_id'] === null ? null : ShiftType::query()->find($request['shift_type_id']);
            $requestedInterval = $intervals->forDate(CarbonImmutable::parse($request['request_date']), $shift);
            foreach ($existing->where('doctor_id', $request['doctor_id']) as $candidate) {
                if ($candidate->request_type !== $request['request_type'] && $intervals->overlaps($requestedInterval, $intervals->forRequest($candidate))) {
                    $this->fail("Existing October request overlaps the expected request for doctor {$request['doctor']} on {$request['request_date']}.");
                }
            }
            if ($equivalent !== null) {
                $matches = $existing->filter(fn (DoctorRequest $candidate): bool => $candidate->doctor_id === $request['doctor_id']
                    && $candidate->request_date->toDateString() === $request['request_date'] && $candidate->request_type === $request['request_type']
                    && $candidate->shift_type_id === $request['shift_type_id']);
                if ($matches->count() > 1) {
                    $this->fail("Duplicate October requests already exist for doctor {$request['doctor']} on {$request['request_date']}.");
                }
                continue;
            }
            $sameIdentity = $existing->first(fn (DoctorRequest $candidate): bool => $candidate->doctor_id === $request['doctor_id']
                && $candidate->request_date->toDateString() === $request['request_date'] && $candidate->request_type === $request['request_type']);
            if ($sameIdentity !== null) {
                $this->fail("Existing October request conflicts with the expected request for doctor {$request['doctor']} on {$request['request_date']}.");
            }
            $missing[] = [
                'doctor_id' => $request['doctor_id'],
                'request_type' => $request['request_type'],
                'request_date' => $request['request_date'],
                'shift_type_id' => $request['shift_type_id'],
            ];
        }

        return $missing;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['october_2026_requests' => $message]);
    }
}
