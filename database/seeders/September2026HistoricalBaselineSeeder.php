<?php

namespace Database\Seeders;

use App\Enums\DoctorMonthlyParticipationStatus;
use App\Enums\DoctorMonthlyWorkloadSource;
use App\Models\Doctor;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorMonthlyWorkload;
use App\Services\DoctorMonthlyWorkloadService;
use App\Services\InitialWorkloadSetupService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

class September2026HistoricalBaselineSeeder extends Seeder
{
    private const YEAR = 2026;

    private const MONTH = 9;

    private const EXPECTED_CODES = ['N', 'S', 'H', 'T', 'K', 'E', 'G', 'A', 'M', 'R', 'B', 'L', 'U', 'I', 'C'];

    private const EXPECTED = [
        'N' => [130, 4, 11, true, '2026-09-30 20:00:00'],
        'S' => [140, 4, 8, true, '2026-09-27 16:00:00'],
        'H' => [134, 4, 9, false, '2026-09-22 20:00:00'],
        'T' => [134, 4, 9, false, '2026-09-24 20:00:00'],
        'K' => [132, 5, 9, true, '2026-09-29 20:00:00'],
        'E' => [140, 5, 9, true, '2026-09-30 20:00:00'],
        'G' => [134, 4, 9, true, '2026-09-24 20:00:00'],
        'A' => [140, 5, 7, false, '2026-09-25 20:00:00'],
        'M' => [134, 4, 9, true, '2026-09-26 16:00:00'],
        'R' => [140, 4, 8, false, '2026-09-28 20:00:00'],
        'B' => [126, 4, 10, false, '2026-09-19 16:00:00'],
        'L' => [136, 4, 9, false, '2026-09-23 20:00:00'],
        'U' => [140, 5, 8, true, '2026-09-29 20:00:00'],
        'I' => [140, 4, 7, false, '2026-09-28 20:00:00'],
        'C' => [0, 0, 0, false, null],
    ];

    /** @var array<string, array{kind: string, groups: array<string, string>}> */
    private const ROSTER = [
        '2026-09-01' => ['kind' => 'weekday', 'groups' => ['day' => 'NHSB', 'optional_day' => 'KG', 'evening' => 'TLE', 'optional_evening' => 'M', 'night' => 'RU']],
        '2026-09-02' => ['kind' => 'weekday', 'groups' => ['day' => 'TEGM', 'optional_day' => 'KA', 'evening' => 'SIN', 'optional_evening' => 'L', 'night' => 'BH']],
        '2026-09-03' => ['kind' => 'weekday', 'groups' => ['day' => 'UGES', 'optional_day' => 'MA', 'evening' => 'KRL', 'optional_evening' => 'N', 'night' => 'TI']],
        '2026-09-04' => ['kind' => 'weekday', 'groups' => ['day' => 'KRHL', 'optional_day' => 'GA', 'evening' => 'UBE', 'optional_evening' => 'N', 'night' => 'MS']],
        '2026-09-05' => ['kind' => 'weekend', 'groups' => ['day' => 'HTI', 'optional_day' => 'NKEGRBU', 'night' => 'AL']],
        '2026-09-06' => ['kind' => 'weekend', 'groups' => ['day' => 'RBH', 'optional_day' => 'NSKEGMU', 'night' => 'TI']],
        '2026-09-07' => ['kind' => 'weekday', 'groups' => ['day' => 'NARB', 'optional_day' => 'LG', 'evening' => 'HSU', 'optional_evening' => 'M', 'night' => 'KE']],
        '2026-09-08' => ['kind' => 'weekday', 'groups' => ['day' => 'SRLU', 'optional_day' => 'HG', 'evening' => 'TBI', 'optional_evening' => 'N', 'night' => 'MA']],
        '2026-09-09' => ['kind' => 'weekday', 'groups' => ['day' => 'KITG', 'optional_day' => 'LU', 'evening' => 'NER', 'optional_evening' => 'B', 'night' => 'SH']],
        '2026-09-10' => ['kind' => 'weekday', 'groups' => ['day' => 'IERU', 'optional_day' => 'NB', 'evening' => 'TMA', 'optional_evening' => 'L', 'night' => 'GK']],
        '2026-09-11' => ['kind' => 'weekday', 'groups' => ['day' => 'NIAT', 'optional_day' => 'MR', 'evening' => 'USH', 'optional_evening' => 'E', 'night' => 'LB']],
        '2026-09-12' => ['kind' => 'weekend', 'groups' => ['day' => 'MGU', 'optional_day' => 'SHTKARI', 'night' => 'EN']],
        '2026-09-13' => ['kind' => 'weekend', 'groups' => ['day' => 'SKM', 'optional_day' => 'HTARBLI', 'night' => 'GU']],
        '2026-09-14' => ['kind' => 'weekday', 'groups' => ['day' => 'HNBL', 'optional_day' => 'TE', 'evening' => 'MSR', 'optional_evening' => 'I', 'night' => 'AK']],
        '2026-09-15' => ['kind' => 'weekday', 'groups' => ['day' => 'GSTL', 'optional_day' => 'HU', 'evening' => 'NMB', 'optional_evening' => 'I', 'night' => 'ER']],
        '2026-09-16' => ['kind' => 'weekday', 'groups' => ['day' => 'NILT', 'optional_day' => 'HK', 'evening' => 'AGU', 'optional_evening' => 'M', 'night' => 'BS']],
        '2026-09-17' => ['kind' => 'weekday', 'groups' => ['day' => 'MAIL', 'optional_day' => 'ER', 'evening' => 'GNK', 'optional_evening' => 'H', 'night' => 'TU']],
        '2026-09-18' => ['kind' => 'weekday', 'groups' => ['day' => 'KMAS', 'optional_day' => 'ER', 'evening' => 'HIL', 'optional_evening' => 'B', 'night' => 'GN']],
        '2026-09-19' => ['kind' => 'weekend', 'groups' => ['day' => 'ALR', 'optional_day' => 'STKEMUI', 'night' => 'BH']],
        '2026-09-20' => ['kind' => 'weekend', 'groups' => ['day' => 'TIA', 'optional_day' => 'NSKEGMU', 'night' => 'LR']],
        '2026-09-21' => ['kind' => 'weekday', 'groups' => ['day' => 'GIUN', 'optional_day' => 'ST', 'evening' => 'HBK', 'optional_evening' => 'A', 'night' => 'EM']],
        '2026-09-22' => ['kind' => 'weekday', 'groups' => ['day' => 'BSKI', 'optional_day' => 'LU', 'evening' => 'TGR', 'optional_evening' => 'N', 'night' => 'AH']],
        '2026-09-23' => ['kind' => 'weekday', 'groups' => ['day' => 'GTRB', 'optional_day' => 'NI', 'evening' => 'SEM', 'optional_evening' => 'K', 'night' => 'LU']],
        '2026-09-24' => ['kind' => 'weekday', 'groups' => ['day' => 'HRKM', 'optional_day' => 'SE', 'evening' => 'NAI', 'optional_evening' => 'B', 'night' => 'GT']],
        '2026-09-25' => ['kind' => 'weekday', 'groups' => ['day' => 'HSEU', 'optional_day' => 'BL', 'evening' => 'RKM', 'optional_evening' => 'N', 'night' => 'IA']],
        '2026-09-26' => ['kind' => 'weekend', 'groups' => ['day' => 'ENS', 'optional_day' => 'HTGRBLU', 'night' => 'KM']],
        '2026-09-27' => ['kind' => 'weekend', 'groups' => ['day' => 'GUE', 'optional_day' => 'HTARBLI', 'night' => 'NS']],
        '2026-09-28' => ['kind' => 'weekday', 'groups' => ['day' => 'EUKL', 'optional_day' => 'TB', 'evening' => 'GAH', 'optional_evening' => 'M', 'night' => 'IR']],
        '2026-09-29' => ['kind' => 'weekday', 'groups' => ['day' => 'AMBE', 'optional_day' => 'SH', 'evening' => 'LGT', 'optional_evening' => 'N', 'night' => 'KU']],
        '2026-09-30' => ['kind' => 'weekday', 'groups' => ['day' => 'AHRM', 'optional_day' => 'TG', 'evening' => 'BIL', 'optional_evening' => 'S', 'night' => 'NE']],
    ];

    public function run(InitialWorkloadSetupService $setup, DoctorMonthlyWorkloadService $workloads): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('September 2026 historical baseline seeding is limited to local and testing environments.');
        }

        $this->assertDoctorPopulation();
        $facts = $this->calculateFacts(self::ROSTER);
        $entries = $this->entries($facts);

        $existingWorkloads = DoctorMonthlyWorkload::query()->where('year', self::YEAR)->where('month', self::MONTH)->get();
        $existingParticipation = DoctorMonthlyParticipation::query()->where('year', self::YEAR)->where('month', self::MONTH)->get();
        if ($existingWorkloads->isNotEmpty() || $existingParticipation->isNotEmpty()) {
            $this->assertMatchingBaseline($existingWorkloads, $existingParticipation, $facts, $workloads);

            return;
        }

        $this->assertNoLaterWorkload();
        $setup->save(self::YEAR, self::MONTH, $entries);
    }

    /** @param array<string, array{kind: string, groups: array<string, string>}> $roster
     * @return array<string, array{hours: int, nights: int, optional: int, final_weekend: bool, most_recent_night: string|null}>
     */
    public function calculateFacts(array $roster): array
    {
        $codes = self::EXPECTED_CODES;
        $facts = array_fill_keys($codes, ['hours' => 0, 'nights' => 0, 'optional' => 0, 'final_weekend' => false, 'most_recent_night' => null]);
        $weekdayCount = 0;
        $weekendCount = 0;
        $totals = ['hours' => 0, 'nights' => 0, 'optional' => 0, 'main' => 0];

        if (count($roster) !== 30) {
            $this->fail('The September source roster must contain exactly 30 dates.');
        }

        foreach ($roster as $date => $day) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false) {
                $this->fail("Invalid source date {$date}.");
            }
            $isWeekend = $parsed->isWeekend();
            $expectedKind = $isWeekend ? 'weekend' : 'weekday';
            if ($parsed->format('Y-m-d') !== $date || $parsed->format('Y-m') !== '2026-09' || $day['kind'] !== $expectedKind) {
                $this->fail("Invalid date classification for {$date}.");
            }
            $isWeekend ? $weekendCount++ : $weekdayCount++;
            $requiredGroups = $isWeekend ? ['day' => 3, 'optional_day' => 7, 'night' => 2] : ['day' => 4, 'optional_day' => 2, 'evening' => 3, 'optional_evening' => 1, 'night' => 2];
            if (array_keys($day['groups']) !== array_keys($requiredGroups)) {
                $this->fail("Shift categories do not match {$expectedKind} capacity on {$date}.");
            }
            $seen = [];
            foreach ($requiredGroups as $group => $capacity) {
                $doctors = str_split(str_replace(' ', '', $day['groups'][$group]));
                if (count($doctors) !== $capacity) {
                    $this->fail("{$date} {$group} must contain {$capacity} doctors.");
                }
                foreach ($doctors as $code) {
                    if (! in_array($code, $codes, true)) {
                        $this->fail("Unknown doctor code {$code} on {$date}.");
                    }
                    if (isset($seen[$code])) {
                        $this->fail("Doctor {$code} is assigned more than once on {$date}.");
                    }
                    $seen[$code] = true;
                    $isOptional = str_starts_with($group, 'optional');
                    $isNight = $group === 'night';
                    $hours = $isOptional ? 0 : match ($group) {
                        'day' => $isWeekend ? 8 : 6, 'evening' => 6, 'night' => $isWeekend ? 16 : 12
                    };
                    $facts[$code]['hours'] += $hours;
                    $facts[$code]['optional'] += (int) $isOptional;
                    $facts[$code]['nights'] += (int) $isNight;
                    $totals['hours'] += $hours;
                    $totals['optional'] += (int) $isOptional;
                    $totals['nights'] += (int) $isNight;
                    $totals['main'] += (int) ! $isOptional;
                    if ($isNight) {
                        $startedAt = $date.' '.($isWeekend ? '16:00:00' : '20:00:00');
                        if ($facts[$code]['most_recent_night'] === null || $startedAt > $facts[$code]['most_recent_night']) {
                            $facts[$code]['most_recent_night'] = $startedAt;
                        }
                    }
                    if (in_array($date, ['2026-09-26', '2026-09-27'], true) && ! $isOptional) {
                        $facts[$code]['final_weekend'] = true;
                    }
                }
            }
        }

        if ($weekdayCount !== 22 || $weekendCount !== 8 || $totals !== ['hours' => 1900, 'nights' => 60, 'optional' => 122, 'main' => 238]) {
            $this->fail('The September source roster does not match the required date or assignment checksums.');
        }
        foreach (self::EXPECTED as $code => [$hours, $nights, $optional, $finalWeekend, $mostRecentNight]) {
            if ($facts[$code] !== ['hours' => $hours, 'nights' => $nights, 'optional' => $optional, 'final_weekend' => $finalWeekend, 'most_recent_night' => $mostRecentNight]) {
                $this->fail("Calculated historical facts for doctor {$code} do not match the confirmed values.");
            }
        }

        return $facts;
    }

    /** @return array<string, array{kind: string, groups: array<string, string>}> */
    public function sourceRoster(): array
    {
        return self::ROSTER;
    }

    /** @param array<string, array{hours: int, nights: int, optional: int, final_weekend: bool, most_recent_night: string|null}> $facts
     * @return list<array{doctor_id: int, participation_status: string, actual_hours: string, actual_night_duty_count: int, optional_assignment_count: int, worked_final_weekend: bool, most_recent_night_shift_at: string|null}>
     */
    public function entries(array $facts): array
    {
        $doctors = Doctor::query()->get()->keyBy('short_code');

        return array_map(fn (string $code): array => [
            'doctor_id' => $doctors->get($code)->id,
            'participation_status' => $code === 'C' ? DoctorMonthlyParticipationStatus::NotPartOfTeam->value : DoctorMonthlyParticipationStatus::Participating->value,
            'actual_hours' => (string) $facts[$code]['hours'],
            'actual_night_duty_count' => $facts[$code]['nights'],
            'optional_assignment_count' => $facts[$code]['optional'],
            'worked_final_weekend' => $facts[$code]['final_weekend'],
            'most_recent_night_shift_at' => $facts[$code]['most_recent_night'],
        ], self::EXPECTED_CODES);
    }

    private function assertDoctorPopulation(): void
    {
        $codes = Doctor::query()->orderBy('short_code')->pluck('short_code')->all();
        $expected = self::EXPECTED_CODES;
        sort($codes);
        sort($expected);
        if ($codes !== $expected || Doctor::query()->count() !== count(self::EXPECTED_CODES)) {
            $this->fail('The doctor population must contain each expected September code exactly once and no other doctors.');
        }
    }

    private function assertNoLaterWorkload(): void
    {
        if (DoctorMonthlyWorkload::query()->whereRaw('(year * 12 + month) > ?', [self::YEAR * 12 + self::MONTH])->exists()) {
            $this->fail('Later workload history exists; refusing to recalculate unrelated months while installing the September baseline.');
        }
    }

    /** @param Collection<int, DoctorMonthlyWorkload> $rows
     * @param  Collection<int, DoctorMonthlyParticipation>  $participation
     * @param  array<string, array{hours: int, nights: int, optional: int, final_weekend: bool, most_recent_night: string|null}>  $facts
     */
    private function assertMatchingBaseline(Collection $rows, Collection $participation, array $facts, DoctorMonthlyWorkloadService $workloads): void
    {
        $doctors = Doctor::query()->get()->keyBy('short_code');
        if ($rows->count() !== 15 || $participation->count() !== 15) {
            $this->fail('September history is partial; refusing to repair or overwrite it.');
        }
        $participationByDoctor = $participation->keyBy('doctor_id');
        $expectedFacts = [];
        foreach (self::EXPECTED_CODES as $code) {
            $doctor = $doctors->get($code);
            $expected = $facts[$code];
            $isParticipating = $code !== 'C';
            $actualParticipation = $participationByDoctor->get($doctor->id);
            $row = $rows->firstWhere('doctor_id', $doctor->id);
            if ($actualParticipation === null || $actualParticipation->roster_id !== null || $actualParticipation->is_participating !== $isParticipating
                || $row === null || $row->roster_id !== null || $row->source !== DoctorMonthlyWorkloadSource::ManualInitial
                || $row->actual_worked_minutes !== $expected['hours'] * 60 || $row->actual_night_duty_count !== $expected['nights']
                || $row->optional_assignment_count !== $expected['optional'] || $row->worked_final_weekend !== $expected['final_weekend']
                || $row->most_recent_night_shift_at?->format('Y-m-d H:i:s') !== $expected['most_recent_night']
                || $row->is_month_excluded || $row->opening_balance_minutes !== 0) {
                $this->fail("Existing September history conflicts with the confirmed baseline for doctor {$code}.");
            }
            $expectedFacts[] = [
                'doctor_id' => $doctor->id,
                'is_participating' => $isParticipating,
                'actual_worked_minutes' => $expected['hours'] * 60,
                'is_month_excluded' => false,
                'opening_balance_minutes' => 0,
                'actual_night_duty_count' => $expected['nights'],
                'optional_assignment_count' => $expected['optional'],
                'worked_final_weekend' => $expected['final_weekend'],
                'most_recent_night_shift_at' => $expected['most_recent_night'],
            ];
        }
        $balanced = collect($workloads->balances($expectedFacts)['rows'])->keyBy('doctor_id');
        foreach ($rows as $row) {
            $expected = $balanced->get($row->doctor_id);
            if ($row->monthly_adjustment_minutes !== $expected['monthly_adjustment_minutes'] || $row->closing_balance_minutes !== $expected['closing_balance_minutes']) {
                $this->fail('Existing September balances do not match the Initial Workload service calculation.');
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['september_baseline' => $message]);
    }
}
