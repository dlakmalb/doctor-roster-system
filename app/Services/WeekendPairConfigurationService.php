<?php

namespace App\Services;

use App\Models\DoctorWeekendGroupMembership;
use App\Models\WeekendPairConfiguration;
use App\Models\WeekendRotationConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WeekendPairConfigurationService
{
    public function __construct(private WeekendGroupRotationService $groups) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, int $createdBy): WeekendPairConfiguration
    {
        $data = Validator::make($input, [
            'group_code' => ['required', Rule::in(['A', 'B'])],
            'effective_saturday' => ['required', 'date_format:Y-m-d'],
            'mode' => ['bail', 'required', 'integer', Rule::in([6, 7])],
            'doctor_ids' => ['required', 'array'],
            'doctor_ids.*' => ['required', 'integer', 'distinct', 'exists:doctors,id'],
            'pair_anchor_phase' => ['required', 'integer', 'between:0,2'],
            'extra_day_anchor_phase' => ['required', 'integer', 'between:0,6'],
        ])->validate();

        if (count($data['doctor_ids']) !== (int) $data['mode']) {
            throw ValidationException::withMessages(['doctor_ids' => 'The number of doctor positions must match the selected mode.']);
        }

        $effective = CarbonImmutable::parse($data['effective_saturday']);
        if (! $effective->isSaturday() || array_keys($data['doctor_ids']) !== range(0, count($data['doctor_ids']) - 1)) {
            throw ValidationException::withMessages(['effective_saturday' => 'Choose a Saturday and supply consecutive ordered doctor positions.']);
        }

        return DB::transaction(function () use ($data, $effective, $createdBy): WeekendPairConfiguration {
            $groupAnchor = $this->groupAnchor(lock: true);
            if ($this->groups->scheduledGroupFor($groupAnchor, $effective) !== $data['group_code']) {
                throw ValidationException::withMessages(['effective_saturday' => 'The effective Saturday must be scheduled for this group.']);
            }
            $this->groups->assertChangeAllowed($effective->toDateString());

            $previous = WeekendPairConfiguration::query()->where('group_code', $data['group_code'])->orderByDesc('effective_saturday')->first();
            if ($previous && ($effective->lte($previous->effective_saturday)
                || (int) $data['pair_anchor_phase'] !== (int) $previous->pair_anchor_phase
                || (int) $data['extra_day_anchor_phase'] !== (int) $previous->extra_day_anchor_phase)) {
                throw ValidationException::withMessages(['effective_saturday' => 'Versions must be appended in date order and retain both anchor phases.']);
            }
            foreach ($data['doctor_ids'] as $doctorId) {
                $membership = DoctorWeekendGroupMembership::query()->where('doctor_id', $doctorId)
                    ->whereDate('effective_from_saturday', '<=', $effective->toDateString())
                    ->orderByDesc('effective_from_saturday')->first();
                if ($membership?->group_code !== $data['group_code']) {
                    throw ValidationException::withMessages(['doctor_ids' => 'Each doctor must belong to the selected group on the effective Saturday.']);
                }
            }
            $configuration = WeekendPairConfiguration::query()->create([
                'group_code' => $data['group_code'],
                'effective_saturday' => $effective->toDateString(),
                'version' => $previous ? $previous->version + 1 : 1,
                'mode' => $data['mode'],
                'pair_anchor_saturday' => $previous?->pair_anchor_saturday?->toDateString() ?? $effective->toDateString(),
                'pair_anchor_phase' => $data['pair_anchor_phase'],
                'extra_day_anchor_phase' => $data['extra_day_anchor_phase'],
                'created_by' => $createdBy,
            ]);
            foreach ($data['doctor_ids'] as $index => $doctorId) {
                $configuration->doctors()->create(['doctor_id' => $doctorId, 'position' => $index + 1]);
            }

            return $configuration->load('doctors');
        });
    }

    public function groupAnchor(bool $lock = false): WeekendRotationConfiguration
    {
        $query = WeekendRotationConfiguration::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $anchors = $query->get();
        try {
            $valid = $anchors->count() === 1
                && in_array($anchors[0]->anchor_group, ['A', 'B'], true)
                && $anchors[0]->anchor_saturday?->isSaturday();
        } catch (\Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['group_code' => 'A single valid weekend group anchor is required.']);
        }

        return $anchors[0];
    }
}
