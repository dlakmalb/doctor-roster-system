<?php

use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\MonthlyWeekendPairConfirmation;
use App\Models\Roster;
use App\Models\User;
use App\Models\WeekendPairConfiguration;
use App\Models\WeekendRotationConfiguration;
use App\Services\MonthlyWeekendPairService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

function weekendPairUiFixture(int $a = 6, int $b = 6): array
{
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-11-07', 'anchor_group' => 'B']);
    $groups = [];
    foreach (['A' => $a, 'B' => $b] as $group => $count) {
        for ($position = 1; $position <= $count; $position++) {
            $doctor = Doctor::query()->create(['name' => "Doctor {$group}{$position}", 'short_code' => "{$group}{$position}", 'is_active' => true]);
            DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => $group, 'effective_from_saturday' => '2026-11-07']);
            $groups[$group][] = $doctor->id;
        }
    }

    return $groups;
}

function weekendPairUiUrl(string $name = 'weekend-pairs.show', int $year = 2026, int $month = 12): string
{
    return route($name, ['year' => $year, 'month' => $month]);
}

function weekendPairUiPayload(array $groups, int $year = 2026, int $month = 12): array
{
    $state = app(MonthlyWeekendPairService::class)->load($year, $month);

    return [
        'revision' => $state['revision'],
        'groups' => [
            'A' => ['effective_saturday' => $state['groups']['A']['effective_saturday'], 'doctor_ids' => $groups['A'], 'weekend_day_main_count' => count($groups['A']) === 7 ? 3 : 2],
            'B' => ['effective_saturday' => $state['groups']['B']['effective_saturday'], 'doctor_ids' => $groups['B'], 'weekend_day_main_count' => count($groups['B']) === 7 ? 3 : 2],
        ],
    ];
}

it('requires authentication for the pair configuration page and browser submission', function () {
    $this->get(weekendPairUiUrl())->assertRedirect(route('login'));
    $this->post(weekendPairUiUrl('weekend-pairs.confirm'), [])->assertRedirect(route('login'));
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
});

it('provides the selected month and full eligible pool without choosing a rotation capacity or writing records', function (int $count) {
    $groups = weekendPairUiFixture($count, $count);

    $this->actingAs(User::factory()->create())->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->component('weekend-pairs')
        ->where('month.year', 2026)->where('month.month', 12)->where('month.label', 'December 2026')
        ->where('configuration.status', 'not_configured')->where('configuration.read_only', false)
        ->has('configuration.groups.A.doctors', $count)->has('configuration.groups.B.doctors', $count)
        ->where('configuration.groups.A.doctors.0.id', $groups['A'][0])
        ->where('configuration.groups.B.doctors.0.id', $groups['B'][0])
        ->where('configuration.groups.A.effective_saturday', '2026-12-12')
        ->where('configuration.groups.B.effective_saturday', '2026-12-05')
        ->where('configuration.groups.A.configuration', null)
        ->where('configuration.groups.A.weekend_day_main_count', null)
        ->where('configuration.groups.B.weekend_day_main_count', null)
        ->where('configuration.issues', [])
        ->where('links.show', weekendPairUiUrl())
        ->where('links.confirm', weekendPairUiUrl('weekend-pairs.confirm'))
        ->where('links.monthlySetup', weekendPairUiUrl('monthly-setup.show'))
        ->where('links.weekendGroups', weekendPairUiUrl('weekend-groups.show')));

    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
})->with([6, 7, 8]);

it('renders missing anchor guidance without creating configuration or participation', function () {
    $this->actingAs(User::factory()->create())->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->component('weekend-pairs')->where('configuration.status', 'requires_attention')
        ->where('configuration.groups', [])
        ->where('configuration.issues.0', 'A single valid weekend group anchor is required.')
        ->where('links.weekendGroups', weekendPairUiUrl('weekend-groups.show')));

    $this->assertDatabaseCount('weekend_rotation_configurations', 0);
    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
});

it('confirms ordered rotation subsets with independent capacities and retains the full eligible pools', function (int $eligibleCount, int $aCount, int $bCount, int $aCapacity, int $bCapacity) {
    $eligible = weekendPairUiFixture($eligibleCount, $eligibleCount);
    $selected = ['A' => array_slice($eligible['A'], 0, $aCount), 'B' => array_slice($eligible['B'], 0, $bCount)];
    [$selected['A'][0], $selected['A'][3]] = [$selected['A'][3], $selected['A'][0]];
    $this->actingAs(User::factory()->create());
    $payload = weekendPairUiPayload($selected);
    $payload['groups']['A']['weekend_day_main_count'] = $aCapacity;
    $payload['groups']['B']['weekend_day_main_count'] = $bCapacity;

    $this->post(weekendPairUiUrl('weekend-pairs.confirm'), $payload)
        ->assertRedirect(weekendPairUiUrl())->assertSessionHasNoErrors();

    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->component('weekend-pairs')->where('configuration.status', 'confirmed')
        ->where('configuration.issues', [])
        ->has('configuration.groups.A.doctors', $eligibleCount)
        ->has('configuration.groups.B.doctors', $eligibleCount)
        ->where('configuration.groups.A.configuration.doctor_ids', $selected['A'])
        ->where('configuration.groups.B.configuration.doctor_ids', $selected['B'])
        ->where('configuration.groups.A.configuration.mode', $aCount)
        ->where('configuration.groups.B.configuration.mode', $bCount)
        ->where('configuration.groups.A.weekend_day_main_count', $aCapacity)
        ->where('configuration.groups.B.weekend_day_main_count', $bCapacity));
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', $aCount + $bCount);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 0);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
})->with([
    'six selected from seven eligible' => [7, 6, 6, 2, 2],
    'seven selected in B' => [7, 6, 7, 2, 3],
    'seven selected in A' => [7, 7, 6, 3, 2],
    'eight eligible with A six and B seven' => [8, 6, 7, 2, 3],
    'eight eligible with A seven and B six' => [8, 7, 6, 3, 2],
]);

it('returns capacity and selection validation errors without creating partial configurations', function (array $positions, int $capacity, array $keys) {
    $groups = weekendPairUiFixture(7, 6);
    $this->actingAs(User::factory()->create());
    $payload = weekendPairUiPayload($groups);
    $payload['groups']['A']['doctor_ids'] = array_map(fn (?int $index): int|string => $index === null ? '' : $groups['A'][$index], $positions);
    $payload['groups']['A']['weekend_day_main_count'] = $capacity;

    $this->from(weekendPairUiUrl())->post(weekendPairUiUrl('weekend-pairs.confirm'), $payload)
        ->assertRedirect(weekendPairUiUrl())->assertSessionHasErrors($keys);

    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 0);
})->with([
    'two Main with seven doctors' => [[0, 1, 2, 3, 4, 5, 6], 2, ['groups.A.weekend_day_main_count' => 'Weekend Day Main capacity must be 2 for six selected rotation doctors or 3 for seven selected rotation doctors.']],
    'three Main with six doctors' => [[0, 1, 2, 3, 4, 5], 3, ['groups.A.weekend_day_main_count' => 'Weekend Day Main capacity must be 2 for six selected rotation doctors or 3 for seven selected rotation doctors.']],
    'unsupported capacity' => [[0, 1, 2, 3, 4, 5], 4, ['groups.A.weekend_day_main_count']],
    'duplicate selection' => [[0, 0, 2, 3, 4, 5], 2, ['groups.A.doctor_ids.0', 'groups.A.doctor_ids.1']],
    'missing position' => [[0, 1, 2, 3, 4], 2, ['groups.A.doctor_ids']],
    'empty seventh position' => [[0, 1, 2, 3, 4, 5, null], 3, ['groups.A.doctor_ids.6']],
]);

it('rejects a currently ineligible rotation doctor with a fresh revision', function () {
    $groups = weekendPairUiFixture(7, 6);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 12]);
    $selected = ['A' => array_slice($groups['A'], 0, 6), 'B' => $groups['B']];
    $this->actingAs(User::factory()->create());
    $payload = weekendPairUiPayload($selected);

    $this->from(weekendPairUiUrl())->post(weekendPairUiUrl('weekend-pairs.confirm'), $payload)
        ->assertRedirect(weekendPairUiUrl())->assertSessionHasErrors('groups.A.doctor_ids');

    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 0);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 1);
});

it('reconfirms the same six selected doctors after the eligible pool grows without replacing configuration history', function () {
    $groups = weekendPairUiFixture();
    $this->actingAs(User::factory()->create());
    $submit = weekendPairUiUrl('weekend-pairs.confirm');
    $this->post($submit, weekendPairUiPayload($groups))->assertSessionHasNoErrors();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail();
    $originalAttributes = $original->getAttributes();
    $originalConfigurations = WeekendPairConfiguration::query()->with('doctors')->orderBy('id')->get()->toArray();
    $doctor = Doctor::query()->create(['name' => 'Additional eligible doctor', 'short_code' => 'B7', 'is_active' => true]);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => 'B', 'effective_from_saturday' => '2026-11-07']);
    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'requires_attention')
        ->has('configuration.groups.B.doctors', 7)
        ->where('configuration.groups.B.configuration.doctor_ids', $groups['B'])
        ->where('configuration.groups.B.weekend_day_main_count', 2));

    $this->post($submit, weekendPairUiPayload($groups))->assertRedirect(weekendPairUiUrl())->assertSessionHasNoErrors();

    $latest = MonthlyWeekendPairConfirmation::query()->latest('id')->firstOrFail();
    expect((int) $latest->supersedes_confirmation_id)->toBe($original->id);
    expect((int) $latest->group_a_configuration_id)->toBe((int) $original->group_a_configuration_id);
    expect((int) $latest->group_b_configuration_id)->toBe((int) $original->group_b_configuration_id);
    expect($original->fresh()->getAttributes())->toBe($originalAttributes);
    expect(WeekendPairConfiguration::query()->with('doctors')->orderBy('id')->get()->toArray())->toBe($originalConfigurations);
    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'confirmed')
        ->where('configuration.groups.B.configuration.doctor_ids', $groups['B'])
        ->where('configuration.groups.B.configuration.version', 1)
        ->where('configuration.groups.B.weekend_day_main_count', 2));
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 0);
});

it('renders issues for missing memberships and fewer than six eligible group doctors', function (string $case) {
    $groups = weekendPairUiFixture($case === 'size' ? 5 : 6);
    if ($case === 'membership') {
        DoctorWeekendGroupMembership::query()->where('doctor_id', $groups['A'][0])->delete();
    }

    $this->actingAs(User::factory()->create())->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->component('weekend-pairs')->where('configuration.status', 'requires_attention')
        ->where('configuration.issues', fn ($issues): bool => count($issues) > 0));

    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
})->with(['membership', 'size']);

it('confirms through the browser adapter then reconfirms only the reordered group with immutable history', function () {
    $groups = weekendPairUiFixture(6, 7);
    $this->actingAs(User::factory()->create());
    $url = weekendPairUiUrl();
    $submit = weekendPairUiUrl('weekend-pairs.confirm');
    $this->post($submit, weekendPairUiPayload($groups))->assertRedirect($url)
        ->assertSessionHas('status', 'Weekend pair configuration confirmed.');
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail();
    $attributes = $original->getAttributes();
    $originalB = WeekendPairConfiguration::query()->with('doctors')->findOrFail($original->group_b_configuration_id);
    $originalIds = $originalB->doctors->pluck('doctor_id')->all();
    $stale = weekendPairUiPayload($groups);
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'confirmed')->where('configuration.confirmation.id', $original->id)
        ->where('configuration.groups.B.configuration.doctor_ids', $groups['B'])
        ->where('configuration.groups.B.configuration.mode', 7));
    $this->post($submit, $stale)->assertRedirect($url)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    [$groups['B'][1], $groups['B'][3]] = [$groups['B'][3], $groups['B'][1]];

    $this->post($submit, weekendPairUiPayload($groups))->assertRedirect($url)->assertSessionHasNoErrors();

    $latest = MonthlyWeekendPairConfirmation::query()->latest('id')->firstOrFail();
    expect((int) $latest->supersedes_confirmation_id)->toBe($original->id);
    expect((int) $latest->group_a_configuration_id)->toBe((int) $original->group_a_configuration_id);
    expect((int) $latest->group_b_configuration_id)->not->toBe((int) $original->group_b_configuration_id);
    expect($original->fresh()->getAttributes())->toBe($attributes);
    expect($originalB->fresh()->doctors->pluck('doctor_id')->all())->toBe($originalIds);
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'confirmed')->where('configuration.confirmation.id', $latest->id)
        ->where('configuration.groups.B.configuration.version', 2)
        ->where('configuration.groups.B.configuration.doctor_ids', $groups['B'])
        ->where('configuration.revision', fn ($revision): bool => $revision !== $stale['revision']));
    $this->post($submit, weekendPairUiPayload($groups))->assertRedirect($url)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);
});

it('returns stale revisions to the Inertia form as validation errors without writes', function () {
    $groups = weekendPairUiFixture(7, 6);
    $this->actingAs(User::factory()->create());
    $payload = weekendPairUiPayload($groups);
    $exclusion = DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][6], 'year' => 2026, 'month' => 12]);
    $originalExclusion = $exclusion->fresh()->getAttributes();

    $this->followingRedirects()->from(weekendPairUiUrl())
        ->post(weekendPairUiUrl('weekend-pairs.confirm'), $payload)
        ->assertInertia(fn (Assert $page) => $page
            ->component('weekend-pairs')
            ->url(route('weekend-pairs.show', ['year' => 2026, 'month' => 12], absolute: false))
            ->where('errors.revision', 'The monthly configuration changed. Reload before confirming.')
            ->where('flash.status', null));
    expect($exclusion->fresh()->getAttributes())->toBe($originalExclusion);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
    $this->assertDatabaseCount('weekend_pair_configurations', 0);
});

it('returns indexed and group validation errors through the browser adapter without partial records', function (string $case, array $keys) {
    $groups = weekendPairUiFixture();
    $this->actingAs(User::factory()->create());
    $payload = weekendPairUiPayload($groups);
    if ($case === 'duplicate') {
        $payload['groups']['B']['doctor_ids'][0] = $groups['A'][0];
    } elseif ($case === 'membership') {
        [$payload['groups']['A']['doctor_ids'][0], $payload['groups']['B']['doctor_ids'][0]] = [$groups['B'][0], $groups['A'][0]];
    } else {
        $payload['groups']['B']['effective_saturday'] = '2026-12-26';
    }

    $this->from(weekendPairUiUrl())->post(weekendPairUiUrl('weekend-pairs.confirm'), $payload)
        ->assertRedirect(weekendPairUiUrl())->assertSessionHasErrors($keys);

    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
    $this->assertDatabaseCount('weekend_pair_configurations', 0);
})->with([
    'duplicate' => ['duplicate', ['groups.A.doctor_ids.0', 'groups.B.doctor_ids.0']],
    'membership' => ['membership', ['groups.A.doctor_ids']],
    'effective date' => ['date', ['groups.B.effective_saturday']],
]);

it('uses backend exclusion eligibility for seven to six and six to seven corrections through the UI', function () {
    $groups = weekendPairUiFixture(7, 6);
    $this->actingAs(User::factory()->create());
    $submit = weekendPairUiUrl('weekend-pairs.confirm');
    $this->post($submit, weekendPairUiPayload($groups))->assertSessionHasNoErrors();
    $doctorId = array_pop($groups['A']);
    $exclusion = DoctorMonthlyExclusion::query()->create(['doctor_id' => $doctorId, 'year' => 2026, 'month' => 12]);
    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'requires_attention')->has('configuration.groups.A.doctors', 6));

    $this->post($submit, weekendPairUiPayload($groups))->assertSessionHasNoErrors();
    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'confirmed')->where('configuration.groups.A.configuration.mode', 6));
    $exclusion->delete();
    $groups['A'][] = $doctorId;
    $this->post($submit, weekendPairUiPayload($groups))->assertSessionHasNoErrors();

    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'confirmed')->where('configuration.groups.A.configuration.mode', 7));
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 3);
    $this->assertDatabaseCount('weekend_pair_configurations', 4);
});

it('shows saved pairs read only and rejects browser confirmation after draft or final creation', function (string $status) {
    $groups = weekendPairUiFixture(7, 7);
    $groups['B'] = array_slice($groups['B'], 0, 6);
    $this->actingAs(User::factory()->create());
    $this->post(weekendPairUiUrl('weekend-pairs.confirm'), weekendPairUiPayload($groups))->assertSessionHasNoErrors();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    $originalConfigurations = WeekendPairConfiguration::query()->with('doctors')->orderBy('id')->get()->toArray();
    $roster = Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => 'draft']);
    snapshotRosterParticipation($roster);
    $roster->update(['status' => $status]);
    $originalRoster = $roster->fresh()->getAttributes();
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 12]);

    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.read_only', true)->where('configuration.groups.A.configuration.doctor_ids', $groups['A'])
        ->where('configuration.groups.A.configuration.mode', 7)
        ->where('configuration.groups.A.weekend_day_main_count', 3)
        ->where('configuration.groups.B.configuration.doctor_ids', $groups['B'])
        ->where('configuration.groups.B.configuration.mode', 6)
        ->where('configuration.groups.B.weekend_day_main_count', 2)
        ->has('configuration.groups.A.doctors', 6)
        ->has('configuration.groups.B.doctors', 7)
        ->has('savedDoctors', 13)->where('savedDoctors.0.id', $groups['A'][0])
        ->where('links.roster', weekendPairUiUrl('rosters.show')));
    $this->from(weekendPairUiUrl())->post(weekendPairUiUrl('weekend-pairs.confirm'), weekendPairUiPayload($groups))
        ->assertRedirect(weekendPairUiUrl())->assertSessionHasErrors('roster');

    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original);
    expect(WeekendPairConfiguration::query()->with('doctors')->orderBy('id')->get()->toArray())->toBe($originalConfigurations);
    expect($roster->fresh()->getAttributes())->toBe($originalRoster);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
})->with(['draft', 'final']);

it('reconfirms changed effective memberships through the same browser workflow', function () {
    $groups = weekendPairUiFixture();
    $this->actingAs(User::factory()->create());
    $submit = weekendPairUiUrl('weekend-pairs.confirm');
    $this->post($submit, weekendPairUiPayload($groups))->assertSessionHasNoErrors();
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['A'][0], 'group_code' => 'B', 'effective_from_saturday' => '2026-12-05']);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['B'][0], 'group_code' => 'A', 'effective_from_saturday' => '2026-12-05']);
    [$groups['A'][0], $groups['B'][0]] = [$groups['B'][0], $groups['A'][0]];
    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'requires_attention')
        ->where('configuration.groups.A.doctors', fn ($doctors): bool => collect($doctors)->pluck('id')->contains($groups['A'][0])));

    $this->post($submit, weekendPairUiPayload($groups))->assertRedirect(weekendPairUiUrl())->assertSessionHasNoErrors();

    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'confirmed')
        ->where('configuration.groups.A.configuration.doctor_ids', $groups['A'])
        ->where('configuration.groups.B.configuration.doctor_ids', $groups['B']));
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('weekend_pair_configurations', 4);
});

it('provides Sunday legacy boundary information without backdating pair configurations', function () {
    weekendPairUiFixture();

    $this->actingAs(User::factory()->create())->get(weekendPairUiUrl(month: 11))->assertInertia(fn (Assert $page) => $page
        ->where('month.label', 'November 2026')->where('configuration.boundary.state', 'legacy')
        ->where('configuration.boundary.saturday', '2026-10-31')->where('configuration.boundary.configuration', null));

    $this->assertDatabaseCount('weekend_pair_configurations', 0);
});

it('provides applicable carry forward pairs and later historical Sunday boundary information', function () {
    $groups = weekendPairUiFixture();
    $this->actingAs(User::factory()->create());
    $this->post(weekendPairUiUrl('weekend-pairs.confirm'), weekendPairUiPayload($groups))->assertSessionHasNoErrors();

    $this->get(weekendPairUiUrl(year: 2027, month: 1))->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'ready_to_confirm')->where('configuration.groups.A.configuration.doctor_ids', $groups['A']));
    $this->get(weekendPairUiUrl(year: 2027, month: 8))->assertInertia(fn (Assert $page) => $page
        ->where('configuration.boundary.state', 'pair_rotation')->where('configuration.boundary.saturday', '2027-07-31')
        ->where('configuration.boundary.compatible', true)->has('configuration.boundary.configuration.id'));

    $this->assertDatabaseCount('weekend_pair_configurations', 2);
});

it('preserves the existing JSON endpoint response contract alongside the UI', function () {
    $groups = weekendPairUiFixture();
    $this->actingAs(User::factory()->create());

    $this->getJson(weekendPairUiUrl('monthly-weekend-pairs.show'))->assertOk()
        ->assertJsonPath('status', 'not_configured')->assertJsonPath('year', 2026)->assertJsonPath('month', 12)
        ->assertJsonMissingPath('component');
    $this->postJson(weekendPairUiUrl('monthly-weekend-pairs.store'), weekendPairUiPayload($groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonStructure(['revision', 'groups', 'confirmation']);
    $this->get(weekendPairUiUrl())->assertInertia(fn (Assert $page) => $page
        ->component('weekend-pairs')->where('configuration.status', 'confirmed'));
});

it('shows an incompatible historical Sunday boundary and refuses browser confirmation', function () {
    $groups = weekendPairUiFixture();
    $this->actingAs(User::factory()->create());
    $this->post(weekendPairUiUrl('weekend-pairs.confirm'), weekendPairUiPayload($groups))->assertSessionHasNoErrors();
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['B'][0], 'group_code' => 'A', 'effective_from_saturday' => '2027-07-31']);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['B'][0], 'group_code' => 'B', 'effective_from_saturday' => '2027-08-07']);
    $url = weekendPairUiUrl(year: 2027, month: 8);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('configuration.status', 'requires_attention')->where('configuration.boundary.compatible', false));
    $this->from($url)->post(weekendPairUiUrl('weekend-pairs.confirm', 2027, 8), weekendPairUiPayload($groups, 2027, 8))
        ->assertRedirect($url)->assertSessionHasErrors('configuration');

    $this->assertDatabaseCount('weekend_pair_configurations', 2);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
});

it('rolls back new versions and reports browser errors when confirmation persistence fails', function () {
    $groups = weekendPairUiFixture();
    $this->actingAs(User::factory()->create());
    $event = 'eloquent.creating: '.MonthlyWeekendPairConfirmation::class;
    $versionsAtFailure = null;
    Event::listen($event, function () use (&$versionsAtFailure): void {
        $versionsAtFailure = WeekendPairConfiguration::query()->count();
        throw ValidationException::withMessages(['configuration' => 'Confirmation could not be persisted.']);
    });

    try {
        $this->from(weekendPairUiUrl())->post(weekendPairUiUrl('weekend-pairs.confirm'), weekendPairUiPayload($groups))
            ->assertRedirect(weekendPairUiUrl())->assertSessionHasErrors('configuration');
    } finally {
        Event::forget($event);
    }

    expect($versionsAtFailure)->toBe(2);
    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
});
