<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\MonthlyWeekendPairConfirmation;
use App\Models\Roster;
use App\Models\WeekendPairConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyWeekendPairService
{
    public function __construct(
        private MonthlyWeekendEligibilityService $eligibility,
        private WeekendGroupRotationService $groups,
        private WeekendPairConfigurationService $configurations,
        private WeekendPairRotationService $rotation,
        private WeekendPairCoordination $coordination,
    ) {}

    /** @return array<string, mixed> */
    public function load(int $year, int $month): array
    {
        $roster = Roster::query()->where('year', $year)->where('month', $month)->first();
        $confirmation = MonthlyWeekendPairConfirmation::query()->where('year', $year)->where('month', $month)->orderByDesc('id')->first();
        $result = ['year' => $year, 'month' => $month, 'read_only' => $roster !== null, 'groups' => [], 'boundary' => null, 'issues' => []];
        try {
            $anchor = $this->configurations->groupAnchor();
            $doctors = $this->eligibility->resolve($year, $month, $roster);
            $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
            $legacyBoundary = $start->isSunday() && ! WeekendPairConfiguration::query()
                ->whereDate('effective_saturday', '<=', $start->subDay()->toDateString())->exists();
            $groupState = $this->groups->forCalendarMonth($year, $month, $doctors, includePreviousMonthWeekend: ! $legacyBoundary);
            if (! $groupState['configured'] || $groupState['error'] !== null) {
                throw ValidationException::withMessages(['membership' => $groupState['error'] ?? 'Configure the weekend group anchor and memberships in Weekend Group Management.']);
            }
            $dates = $this->groups->weekendStartsForMonth($year, $month);
            $membershipByDate = [];
            foreach ($dates as $date) {
                if ($legacyBoundary && $date->lt($start)) {
                    continue;
                }
                $key = $date->toDateString();
                foreach ($doctors as $doctor) {
                    $membershipByDate[$key][$doctor->id] = $this->groups->groupFor($groupState['assignments'], $doctor->id, $key);
                }
            }
            foreach (['A', 'B'] as $group) {
                $first = $dates->first(fn (CarbonImmutable $date): bool => $date->gte($start) && $this->groups->scheduledGroupFor($anchor, $date) === $group);
                if ($first === null) {
                    throw ValidationException::withMessages(['membership' => "No Saturday is scheduled for Group {$group} in this month."]);
                }
                $firstKey = $first->toDateString();
                $eligible = $doctors->filter(fn ($doctor): bool => ($membershipByDate[$firstKey][$doctor->id] ?? null) === $group)->values();
                foreach ($dates->filter(fn (CarbonImmutable $date): bool => $date->gte($start) && $this->groups->scheduledGroupFor($anchor, $date) === $group) as $date) {
                    $ids = $doctors->filter(fn ($doctor): bool => ($membershipByDate[$date->toDateString()][$doctor->id] ?? null) === $group)->pluck('id')->all();
                    if ($ids !== $eligible->pluck('id')->all()) {
                        $result['issues'][] = "Group {$group} membership changes during the month; resolve this in Weekend Group Management.";
                    }
                }
                $current = $this->applicable($group, $firstKey);
                $latest = WeekendPairConfiguration::query()->where('group_code', $group)->orderByDesc('effective_saturday')->orderByDesc('version')->first();
                $later = WeekendPairConfiguration::query()->where('group_code', $group)->whereDate('effective_saturday', '>', $firstKey)
                    ->whereDate('effective_saturday', '<=', $start->endOfMonth()->toDateString())->exists();
                if ($later) {
                    $result['issues'][] = "Group {$group} has a later configuration version within the month.";
                }
                if (! in_array($eligible->count(), [6, 7], true)) {
                    $result['issues'][] = "Group {$group} needs six or seven eligible doctors; review Weekend Group Management.";
                }
                $result['groups'][$group] = [
                    'effective_saturday' => $firstKey,
                    'doctors' => $eligible->map(fn ($doctor): array => ['id' => $doctor->id, 'name' => $doctor->name, 'short_code' => $doctor->short_code])->all(),
                    'configuration' => $this->serializeConfiguration($current),
                    'latest_version_id' => $latest?->id,
                ];
            }
            if ($start->isSunday()) {
                $saturday = $start->subDay()->toDateString();
                $boundaryGroup = $this->groups->scheduledGroupFor($anchor, $start->subDay());
                $historical = $this->applicable($boundaryGroup, $saturday);
                if ($legacyBoundary) {
                    $result['boundary'] = ['saturday' => $saturday, 'group' => $boundaryGroup, 'state' => 'legacy', 'configuration' => null, 'compatible' => null];
                } else {
                    $expected = $doctors->filter(fn ($doctor): bool => ($membershipByDate[$saturday][$doctor->id] ?? null) === $boundaryGroup)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                    $actual = $historical?->doctors->pluck('doctor_id')->map(fn ($id): int => (int) $id)->sort()->values()->all() ?? [];
                    $compatible = $historical !== null && in_array(count($expected), [6, 7], true) && $expected === $actual;
                    if ($compatible) {
                        try {
                            $this->rotation->forSaturday($saturday);
                        } catch (ValidationException) {
                            $compatible = false;
                        }
                    }
                    $result['boundary'] = ['saturday' => $saturday, 'group' => $boundaryGroup, 'state' => 'pair_rotation', 'configuration' => $this->serializeConfiguration($historical), 'compatible' => $compatible];
                    if (! $compatible) {
                        $result['issues'][] = 'The preceding Saturday has no compatible historical pair configuration; the Sunday boundary requires attention.';
                    }
                }
            }
            $population = ['doctors' => $doctors->pluck('id')->all(), 'memberships' => $membershipByDate, 'boundary' => $result['boundary']];
            $result['population_fingerprint'] = hash('sha256', json_encode($population, JSON_THROW_ON_ERROR));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $result['issues'][] = $message;
                }
            }
            $result['population_fingerprint'] = null;
        }
        $confirmedVersions = $confirmation === null ? null : ['A' => (int) $confirmation->group_a_configuration_id, 'B' => (int) $confirmation->group_b_configuration_id];
        $currentVersions = [];
        foreach (['A', 'B'] as $group) {
            $currentVersions[$group] = $result['groups'][$group]['configuration']['id'] ?? null;
        }
        $result['confirmation'] = $confirmation === null ? null : ['id' => $confirmation->id, 'configuration_ids' => $confirmedVersions, 'boundary_configuration_id' => $confirmation->boundary_configuration_id === null ? null : (int) $confirmation->boundary_configuration_id, 'confirmed_at' => $confirmation->updated_at?->toIso8601String()];
        $result['status'] = $result['issues'] !== [] ? 'requires_attention' : ($confirmation !== null
            ? ($confirmation->population_fingerprint === $result['population_fingerprint'] && $confirmedVersions === $currentVersions ? 'confirmed' : 'requires_attention')
            : ($currentVersions['A'] === null || $currentVersions['B'] === null ? 'not_configured' : 'ready_to_confirm'));
        $result['revision'] = hash('sha256', json_encode([$result['population_fingerprint'], $result['groups'], $result['issues'], $result['confirmation'], $roster?->id, $roster?->status->value], JSON_THROW_ON_ERROR));

        return $result;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function confirm(int $year, int $month, array $input, int $userId): array
    {
        return DB::transaction(function () use ($year, $month, $input, $userId): array {
            $this->coordination->lock();
            $this->configurations->groupAnchor(lock: true);
            Doctor::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $state = $this->load($year, $month);
            if (! hash_equals($state['revision'], $input['revision'])) {
                throw ValidationException::withMessages(['revision' => 'The monthly configuration changed. Reload before confirming.']);
            }
            if ($state['read_only']) {
                throw ValidationException::withMessages(['roster' => 'A Draft or Final roster already exists for this month; pair configuration is read-only.']);
            }
            if ($state['issues'] !== []) {
                throw ValidationException::withMessages(['configuration' => $state['issues']]);
            }
            $unchanged = $state['confirmation'] !== null && $state['status'] === 'confirmed';
            foreach (['A', 'B'] as $group) {
                $submitted = array_map('intval', $input['groups'][$group]['doctor_ids']);
                $expected = array_map('intval', array_column($state['groups'][$group]['doctors'], 'id'));
                $sorted = $submitted;
                sort($sorted);
                sort($expected);
                if (! array_is_list($submitted) || count($submitted) !== count(array_unique($submitted)) || $sorted !== $expected) {
                    throw ValidationException::withMessages(["groups.{$group}.doctor_ids" => "Select every eligible Group {$group} doctor exactly once."]);
                }
                $effective = $state['groups'][$group]['effective_saturday'];
                if ($input['groups'][$group]['effective_saturday'] !== $effective) {
                    throw ValidationException::withMessages(["groups.{$group}.effective_saturday" => 'Use the first scheduled Saturday for this group within the selected month.']);
                }
                $unchanged = $unchanged && $submitted === ($state['groups'][$group]['configuration']['doctor_ids'] ?? null);
            }
            if ($unchanged) {
                return $state;
            }
            $saved = [];
            foreach (['A', 'B'] as $group) {
                $submitted = array_map('intval', $input['groups'][$group]['doctor_ids']);
                $effective = $state['groups'][$group]['effective_saturday'];
                $current = $this->applicable($group, $effective);
                $currentIds = $current?->doctors->pluck('doctor_id')->map(fn ($id): int => (int) $id)->all();
                if ($currentIds === $submitted) {
                    $this->rotation->forSaturday($effective);
                    $saved[$group] = $current;

                    continue;
                }
                $effectiveDate = CarbonImmutable::parse($effective);
                if (Roster::query()->where(function ($query) use ($effectiveDate): void {
                    $query->where('year', '>', $effectiveDate->year)
                        ->orWhere(fn ($query) => $query->where('year', $effectiveDate->year)->where('month', '>=', $effectiveDate->month));
                })->exists()) {
                    throw ValidationException::withMessages(['roster' => 'A later Draft or Final roster could be affected by this configuration change.']);
                }
                $latest = WeekendPairConfiguration::query()->where('group_code', $group)->orderByDesc('effective_saturday')->orderByDesc('version')->first();
                $saved[$group] = $this->configurations->create([
                    'group_code' => $group, 'effective_saturday' => $effective, 'mode' => count($submitted), 'doctor_ids' => $submitted,
                    'pair_anchor_phase' => $latest?->pair_anchor_phase ?? 0, 'extra_day_anchor_phase' => $latest?->extra_day_anchor_phase ?? 0,
                ], $userId, $state['confirmation'] === null ? null : $state['confirmation']['id']);
            }
            MonthlyWeekendPairConfirmation::query()->create([
                'year' => $year, 'month' => $month,
                'supersedes_confirmation_id' => $state['confirmation']['id'] ?? null,
                'group_a_configuration_id' => $saved['A']->id, 'group_b_configuration_id' => $saved['B']->id,
                'boundary_configuration_id' => $state['boundary']['configuration']['id'] ?? null,
                'population_fingerprint' => $state['population_fingerprint'], 'confirmed_by' => $userId,
            ]);

            return $this->load($year, $month);
        });
    }

    private function applicable(string $group, string $date): ?WeekendPairConfiguration
    {
        return WeekendPairConfiguration::query()->with('doctors')->where('group_code', $group)
            ->whereDate('effective_saturday', '<=', $date)->orderByDesc('effective_saturday')->orderByDesc('version')->first();
    }

    /** @return array<string, mixed>|null */
    private function serializeConfiguration(?WeekendPairConfiguration $configuration): ?array
    {
        return $configuration === null ? null : [
            'id' => (int) $configuration->id, 'version' => (int) $configuration->version,
            'effective_saturday' => $configuration->effective_saturday->toDateString(),
            'mode' => (int) $configuration->mode,
            'doctor_ids' => $configuration->doctors->pluck('doctor_id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }
}
