<?php

use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyParticipation;
use App\Models\DoctorWeekendGroupMembership;
use App\Models\MonthlyWeekendPairConfirmation;
use App\Models\Roster;
use App\Models\User;
use App\Models\WeekendPairConfiguration;
use App\Models\WeekendRotationConfiguration;
use App\Services\DoctorMonthlyParticipationService;
use App\Services\MonthlyWeekendPairService;
use App\Services\RosterStructureService;
use App\Services\WeekendPairConfigurationService;
use App\Services\WeekendPairCoordination;
use App\Services\WeekendPairRotationService;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

function monthlyPairFixture(int $a = 6, int $b = 6): array
{
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-12-05', 'anchor_group' => 'A']);
    $groups = [];
    foreach (['A' => $a, 'B' => $b] as $group => $count) {
        for ($i = 1; $i <= $count; $i++) {
            $doctor = Doctor::query()->create(['name' => "Doctor {$group}{$i}", 'short_code' => "{$group}{$i}", 'is_active' => true]);
            DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => $group, 'effective_from_saturday' => '2026-11-28']);
            $groups[$group][] = $doctor->id;
        }
    }

    return $groups;
}

function monthlyPairPayload(array $state, array $groups): array
{
    return [
        'revision' => $state['revision'],
        'groups' => [
            'A' => ['effective_saturday' => $state['groups']['A']['effective_saturday'], 'doctor_ids' => $groups['A']],
            'B' => ['effective_saturday' => $state['groups']['B']['effective_saturday'], 'doctor_ids' => $groups['B']],
        ],
    ];
}

it('confirms ordered six or seven doctor cohorts from the full eligible pools', function (int $poolSize, int $selected, ?int $explicitCapacity) {
    $groups = monthlyPairFixture($poolSize, $poolSize);
    $cohorts = ['A' => array_reverse(array_slice($groups['A'], 0, $selected)), 'B' => array_slice($groups['B'], 0, $selected)];
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertOk()
        ->assertJsonCount($poolSize, 'groups.A.doctors')->assertJsonCount($poolSize, 'groups.B.doctors')
        ->assertJsonPath('issues', [])->json();
    $payload = monthlyPairPayload($state, $cohorts);
    if ($explicitCapacity !== null) {
        $payload['groups']['A']['weekend_day_main_count'] = $explicitCapacity;
        $payload['groups']['B']['weekend_day_main_count'] = $explicitCapacity;
    }

    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonCount($poolSize, 'groups.A.doctors')->assertJsonCount($poolSize, 'groups.B.doctors')
        ->assertJsonPath('groups.A.configuration.doctor_ids', $cohorts['A'])
        ->assertJsonPath('groups.B.configuration.doctor_ids', $cohorts['B'])
        ->assertJsonPath('groups.A.configuration.mode', $selected)
        ->assertJsonPath('groups.A.weekend_day_main_count', $selected === 6 ? 2 : 3)->json();

    foreach (['A', 'B'] as $group) {
        $saved = WeekendPairConfiguration::query()->findOrFail($confirmed['confirmation']['configuration_ids'][$group]);
        expect($saved->doctors->pluck('doctor_id')->all())->toBe($cohorts[$group]);
    }
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', $selected * 2);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 0);
    $this->assertDatabaseCount('doctor_monthly_participations', 0);
})->with([
    'six of seven inferred' => [7, 6, null],
    'seven of seven inferred' => [7, 7, null],
    'six of eight explicit' => [8, 6, 2],
    'seven of eight explicit' => [8, 7, 3],
]);

it('rejects incompatible explicit group capacities with 422 and no partial configuration', function (int $selected, mixed $capacity) {
    $groups = monthlyPairFixture(8, 8);
    $groups = array_map(fn (array $ids): array => array_slice($ids, 0, $selected), $groups);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $groups);
    $payload['groups']['B']['weekend_day_main_count'] = $capacity;

    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors('groups.B.weekend_day_main_count');

    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
})->with([
    'six with three Main' => [6, 3],
    'seven with two Main' => [7, 2],
    'unsupported capacity' => [6, 4],
    'noninteger capacity' => [6, 2.5],
    'explicit null' => [6, null],
]);

it('accepts independent explicit per-group capacities through the JSON endpoint', function (int $a, int $b, int $aMain, int $bMain) {
    $groups = monthlyPairFixture(7, 7);
    $selected = ['A' => array_slice($groups['A'], 0, $a), 'B' => array_slice($groups['B'], 0, $b)];
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $selected);
    $payload['groups']['A']['weekend_day_main_count'] = $aMain;
    $payload['groups']['B']['weekend_day_main_count'] = $bMain;

    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('groups.A.configuration.mode', $a)->assertJsonPath('groups.B.configuration.mode', $b)
        ->assertJsonPath('groups.A.weekend_day_main_count', $aMain)->assertJsonPath('groups.B.weekend_day_main_count', $bMain);

    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 13);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
})->with([
    'A three B two' => [7, 6, 3, 2],
    'A two B three' => [6, 7, 2, 3],
]);

it('requires both groups before confirming a selected cohort', function (string $missingGroup) {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $groups);
    unset($payload['groups'][$missingGroup]);

    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors("groups.{$missingGroup}");

    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
})->with(['A', 'B']);

it('retains the established population fingerprint for a previously confirmed historical boundary', function () {
    $groups = monthlyPairFixture();
    $admin = User::factory()->create();
    $this->actingAs($admin);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $december = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    $memberships = [];
    foreach ($groups as $group => $ids) {
        foreach ($ids as $id) {
            $memberships[$id] = $group;
        }
    }
    $historicalConfiguration = [
        'id' => $december['confirmation']['configuration_ids']['A'], 'version' => 1,
        'effective_saturday' => '2026-12-05', 'mode' => 6, 'doctor_ids' => $groups['A'],
    ];
    $legacyFingerprint = hash('sha256', json_encode([
        'doctors' => array_merge($groups['A'], $groups['B']),
        'memberships' => array_fill_keys(['2027-07-31', '2027-08-07', '2027-08-14', '2027-08-21', '2027-08-28'], $memberships),
        'boundary' => ['saturday' => '2027-07-31', 'group' => 'A', 'state' => 'pair_rotation', 'configuration' => $historicalConfiguration, 'compatible' => true],
    ], JSON_THROW_ON_ERROR));
    MonthlyWeekendPairConfirmation::query()->create([
        'year' => 2027, 'month' => 8, 'group_a_configuration_id' => $december['confirmation']['configuration_ids']['A'],
        'group_b_configuration_id' => $december['confirmation']['configuration_ids']['B'],
        'boundary_configuration_id' => $historicalConfiguration['id'], 'population_fingerprint' => $legacyFingerprint, 'confirmed_by' => $admin->id,
    ]);

    $this->getJson('/monthly-weekend-pairs/2027/8')->assertOk()->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('population_fingerprint', $legacyFingerprint)->assertJsonPath('boundary.configuration', $historicalConfiguration);

    $this->assertDatabaseCount('weekend_pair_configurations', 2);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
});

it('rejects eight selected doctors with 422 while retaining the full eligible pool', function () {
    $groups = monthlyPairFixture(8, 6);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonCount(8, 'groups.A.doctors')->json();

    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertUnprocessable()->assertJsonValidationErrors('groups.A.doctor_ids');

    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
});

it('rejects ineligible selected doctors with 422 even when six other eligible doctors remain', function (string $reason) {
    $groups = monthlyPairFixture(7, 7);
    $selectedId = $groups['B'][0];
    if ($reason === 'inactive') {
        Doctor::query()->whereKey($selectedId)->update(['is_active' => false]);
    } elseif ($reason === 'excluded') {
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $selectedId, 'year' => 2026, 'month' => 12]);
    } elseif ($reason === 'wrong group') {
        $selectedId = $groups['A'][0];
    } else {
        $selectedId = 999999;
    }
    $cohorts = array_map(fn (array $ids): array => array_slice($ids, 0, 6), $groups);
    $cohorts['B'][0] = $selectedId;
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();

    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $cohorts))->assertUnprocessable();

    $this->assertDatabaseCount('weekend_pair_configurations', 0);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 0);
})->with(['inactive', 'excluded', 'wrong group', 'missing doctor']);

it('reconfirms an expanded population using the same immutable selected configuration versions', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $initial = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail();
    $attributes = $original->getAttributes();
    $versions = WeekendPairConfiguration::query()->with('doctors')->orderBy('id')->get()->toArray();
    $extra = Doctor::query()->create(['name' => 'Ordinary participant', 'short_code' => 'A7', 'is_active' => true]);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $extra->id, 'group_code' => 'A', 'effective_from_saturday' => '2026-12-05']);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'requires_attention')->json();

    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($initial, $groups))
        ->assertUnprocessable()->assertJsonValidationErrors('revision');
    $reconfirmed = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonCount(7, 'groups.A.doctors')
        ->assertJsonPath('groups.A.configuration.doctor_ids', $groups['A'])->json();

    expect($state['population_fingerprint'])->not->toBe($initial['population_fingerprint']);
    expect($reconfirmed['confirmation']['configuration_ids'])->toBe($initial['confirmation']['configuration_ids']);
    expect($original->fresh()->getAttributes())->toBe($attributes);
    expect(WeekendPairConfiguration::query()->with('doctors')->orderBy('id')->get()->toArray())->toBe($versions);
    $this->assertDatabaseHas('monthly_weekend_pair_confirmations', ['id' => $reconfirmed['confirmation']['id'], 'supersedes_confirmation_id' => $original->id]);
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($reconfirmed, $groups))->assertExactJson($reconfirmed);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
});

it('versions a changed selected cohort while leaving ordinary participation and the old positions intact', function () {
    $groups = monthlyPairFixture(7, 6);
    $selected = ['A' => array_slice($groups['A'], 0, 6), 'B' => $groups['B']];
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $initial = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $selected))->assertOk()->json();
    $old = WeekendPairConfiguration::query()->with('doctors')->findOrFail($initial['confirmation']['configuration_ids']['A']);
    $before = $old->toArray();
    $selected['A'][2] = $groups['A'][6];

    $corrected = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($initial, $selected))
        ->assertOk()->assertJsonPath('groups.A.configuration.version', 2)
        ->assertJsonPath('groups.A.configuration.doctor_ids', $selected['A'])->json();

    expect($corrected['population_fingerprint'])->toBe($initial['population_fingerprint']);
    expect($old->fresh()->load('doctors')->toArray())->toBe($before);
    $new = WeekendPairConfiguration::query()->findOrFail($corrected['confirmation']['configuration_ids']['A']);
    expect($new->pair_anchor_saturday)->toEqual($old->pair_anchor_saturday);
    expect($new->pair_anchor_phase)->toBe($old->pair_anchor_phase);
    expect($new->extra_day_anchor_phase)->toBe($old->extra_day_anchor_phase);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
});

it('requires authentication for monthly pair endpoints', function () {
    $this->getJson('/monthly-weekend-pairs/2026/12')->assertUnauthorized();
    $this->postJson('/monthly-weekend-pairs/2026/12', [])->assertUnauthorized();
});

it('rejects a stale confirmation after the population changes', function (string $change) {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $groups);
    if ($change === 'membership') {
        DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['A'][0], 'group_code' => 'B', 'effective_from_saturday' => '2026-12-05']);
    } elseif ($change === 'exclusion') {
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 12]);
    } elseif ($change === 'status') {
        Doctor::query()->whereKey($groups['A'][0])->update(['is_active' => false]);
    } elseif ($change === 'participation') {
        app(DoctorMonthlyParticipationService::class)->saveBaseline($groups['A'][0], 2026, 12, false);
    } else {
        $this->seed(ShiftTypesSeeder::class);
        app(RosterStructureService::class)->create(2026, 12, User::factory()->create());
    }
    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('revision');
    expect(WeekendPairConfiguration::query()->count())->toBe(0)
        ->and(MonthlyWeekendPairConfirmation::query()->count())->toBe(0);
})->with(['membership', 'exclusion', 'status', 'participation', 'roster']);

it('takes the coordination lock inside a transaction without a rotation anchor', function () {
    $lockTransactionLevels = [];
    DB::listen(function ($query) use (&$lockTransactionLevels): void {
        if (str_contains(strtolower($query->sql), 'update') && str_contains($query->sql, 'weekend_pair_coordination')) {
            $lockTransactionLevels[] = DB::transactionLevel();
        }
    });
    $doctor = Doctor::query()->create(['name' => 'Doctor', 'short_code' => 'D', 'is_active' => true]);
    app(DoctorMonthlyParticipationService::class)->saveBaseline($doctor->id, 2026, 12, true);
    $this->assertDatabaseHas('doctor_monthly_participations', ['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 12]);
    expect($lockTransactionLevels)->not->toBeEmpty();
    expect(min($lockTransactionLevels))->toBeGreaterThan(0);
});

it('holds the coordination row against a second MySQL transaction', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL row-lock contention requires two MySQL connections.');
    }
    $connectionName = DB::getDefaultConnection();
    config(['database.connections.weekend_pair_probe' => config("database.connections.{$connectionName}")]);
    $probe = DB::connection('weekend_pair_probe');
    $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $lockError = null;
    try {
        DB::transaction(function () use ($probe, &$lockError): void {
            app(WeekendPairCoordination::class)->lock();
            $probe->beginTransaction();
            try {
                $probe->table('weekend_pair_coordination')->where('id', 1)->update(['id' => DB::raw('id')]);
            } catch (QueryException $exception) {
                $lockError = $exception;
            } finally {
                $probe->rollBack();
            }
        });
        expect($lockError)->toBeInstanceOf(QueryException::class)
            ->and((int) $lockError->errorInfo[1])->toBe(1205);
    } finally {
        DB::purge('weekend_pair_probe');
    }
});

it('reports a missing anchor on a fresh database', function () {
    $this->actingAs(User::factory()->create())->getJson('/monthly-weekend-pairs/2026/12')
        ->assertOk()->assertJsonPath('status', 'requires_attention')
        ->assertJsonPath('issues.0', 'A single valid weekend group anchor is required.');
    expect(WeekendPairConfiguration::query()->count())->toBe(0);
});

it('confirms six and seven doctor groups atomically and references exact versions', function () {
    $groups = monthlyPairFixture(6, 7);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertOk()->assertJsonPath('status', 'not_configured')->json();
    $payload = monthlyPairPayload($state, $groups);
    $invalid = $payload;
    $ineligible = Doctor::query()->create(['name' => 'Inactive Doctor', 'short_code' => 'OFF', 'is_active' => false]);
    $invalid['groups']['B']['doctor_ids'][0] = $ineligible->id;
    $this->postJson('/monthly-weekend-pairs/2026/12', $invalid)->assertUnprocessable();
    expect(WeekendPairConfiguration::query()->count())->toBe(0);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $groups);
    $response = $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertOk()->assertJsonPath('status', 'confirmed')->json();
    $confirmation = MonthlyWeekendPairConfirmation::query()->firstOrFail();
    expect((int) $confirmation->group_a_configuration_id)->toBe($response['groups']['A']['configuration']['id'])
        ->and((int) $confirmation->group_b_configuration_id)->toBe($response['groups']['B']['configuration']['id'])
        ->and($response['groups']['A']['configuration']['mode'])->toBe(6)
        ->and($response['groups']['B']['configuration']['mode'])->toBe(7)
        ->and(WeekendPairConfiguration::query()->count())->toBe(2);
    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable()->assertJsonValidationErrors('revision');
});

it('keeps identical confirmations idempotent and appends changed pair ordering', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $initial = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($initial, $groups))
        ->assertJsonPath('status', 'confirmed')->json();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($confirmed, $groups))->assertJsonPath('status', 'confirmed');
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    [$groups['B'][1], $groups['B'][3]] = [$groups['B'][3], $groups['B'][1]];
    $corrected = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($confirmed, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->json();
    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original)
        ->and(WeekendPairConfiguration::query()->count())->toBe(3);
    expect($corrected['population_fingerprint'])->toBe($confirmed['population_fingerprint']);
    expect($corrected['confirmation']['configuration_ids']['A'])->toBe($confirmed['confirmation']['configuration_ids']['A']);
    expect($corrected['confirmation']['configuration_ids']['B'])->not->toBe($confirmed['confirmation']['configuration_ids']['B']);
    $this->assertDatabaseHas('monthly_weekend_pair_confirmations', [
        'id' => $corrected['confirmation']['id'], 'supersedes_confirmation_id' => $original['id'],
        'group_a_configuration_id' => $original['group_a_configuration_id'],
    ]);
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($corrected, $groups))->assertExactJson($corrected);
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($confirmed, $groups))
        ->assertUnprocessable()->assertJsonPath('errors.revision.0', 'The monthly configuration changed. Reload before confirming.');
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);
});

it('rejects missing, duplicated, wrong group and invalid date submissions', function (string $case) {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $groups);
    if ($case === 'missing') {
        array_pop($payload['groups']['A']['doctor_ids']);
    } elseif ($case === 'duplicate') {
        $payload['groups']['A']['doctor_ids'][0] = $groups['A'][1];
    } elseif ($case === 'wrong group') {
        $payload['groups']['A']['doctor_ids'][0] = $groups['B'][0];
    } else {
        $payload['groups']['A']['effective_saturday'] = '2026-12-06';
    }
    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable();
    expect(MonthlyWeekendPairConfirmation::query()->count())->toBe(0)
        ->and(WeekendPairConfiguration::query()->count())->toBe(0);
})->with(['missing', 'duplicate', 'wrong group', 'date']);

it('excludes whole month excluded doctors before roster creation', function () {
    $groups = monthlyPairFixture(7, 7);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][6], 'year' => 2026, 'month' => 12]);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['B'][6], 'year' => 2026, 'month' => 12]);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertOk()->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertUnprocessable();
    array_pop($groups['A']);
    array_pop($groups['B']);
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->assertJsonPath('status', 'confirmed');
    expect(Roster::query()->count())->toBe(0);
});

it('rejects existing pre-roster participation records without modifying them', function (bool $participating, bool $active) {
    $groups = monthlyPairFixture();
    $doctorId = $groups['A'][0];
    Doctor::query()->whereKey($doctorId)->update(['is_active' => $active]);
    $record = DoctorMonthlyParticipation::query()->create([
        'doctor_id' => $doctorId, 'year' => 2026, 'month' => 12, 'is_participating' => $participating,
    ]);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertOk()
        ->assertJsonPath('status', 'requires_attention')
        ->assertJsonPath('issues.0', 'Participation records already exist for this month without a roster. Ask an administrator to resolve the participation history before confirming pairs or creating a roster; do not delete historical records.')
        ->json();
    $payload = [
        'revision' => $state['revision'],
        'groups' => [
            'A' => ['effective_saturday' => '2026-12-05', 'doctor_ids' => $groups['A']],
            'B' => ['effective_saturday' => '2026-12-12', 'doctor_ids' => $groups['B']],
        ],
    ];
    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable()->assertJsonValidationErrors('configuration');
    $this->assertDatabaseHas('doctor_monthly_participations', [
        'id' => $record->id, 'doctor_id' => $doctorId, 'roster_id' => null, 'is_participating' => $participating,
    ]);
    expect(MonthlyWeekendPairConfirmation::query()->count())->toBe(0)
        ->and(WeekendPairConfiguration::query()->count())->toBe(0);
})->with([
    'matching active participation' => [true, true],
    'active nonparticipant' => [false, true],
    'inactive participant' => [true, false],
    'matching inactive nonparticipant' => [false, false],
]);

it('keeps pair eligibility consistent when the roster snapshots active doctors', function (int $count) {
    $groups = monthlyPairFixture($count + 1, $count);
    $excludedId = array_pop($groups['A']);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $excludedId, 'year' => 2026, 'month' => 12]);
    $admin = User::factory()->create();
    $this->actingAs($admin);
    $before = $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'not_configured')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($before, $groups))->assertJsonPath('status', 'confirmed');
    $this->seed(ShiftTypesSeeder::class);
    $roster = app(RosterStructureService::class)->create(2026, 12, $admin);
    $after = $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('read_only', true)->json();
    expect(array_column($after['groups']['A']['doctors'], 'id'))->toBe($groups['A'])
        ->and(array_column($after['groups']['B']['doctors'], 'id'))->toBe($groups['B'])
        ->and($after['population_fingerprint'])->toBe($before['population_fingerprint']);
    $this->assertDatabaseHas('doctor_monthly_participations', [
        'doctor_id' => $excludedId, 'roster_id' => $roster->id, 'is_participating' => true,
    ]);
})->with([6, 7]);

it('detects participation records added after confirmation without altering them', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertJsonPath('status', 'confirmed');
    $record = DoctorMonthlyParticipation::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 12, 'is_participating' => false]);
    $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'requires_attention');
    $this->assertDatabaseHas('doctor_monthly_participations', ['id' => $record->id, 'roster_id' => null, 'is_participating' => false]);
    expect(MonthlyWeekendPairConfirmation::query()->count())->toBe(1);
});

it('carries compatible versions forward without duplicates and detects subsequent participation changes', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $december = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($december, $groups))->assertOk();
    $january = $this->getJson('/monthly-weekend-pairs/2027/1')->assertJsonPath('status', 'ready_to_confirm')->json();
    $this->postJson('/monthly-weekend-pairs/2027/1', monthlyPairPayload($january, $groups))->assertJsonPath('status', 'confirmed');
    expect(WeekendPairConfiguration::query()->count())->toBe(2);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2027, 'month' => 1]);
    $this->getJson('/monthly-weekend-pairs/2027/1')->assertJsonPath('status', 'requires_attention');
});

it('retains pair phase through six to seven and seven to six transitions', function () {
    $groups = monthlyPairFixture(7, 6);
    $extra = array_pop($groups['A']);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $extra, 'year' => 2026, 'month' => 12]);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $groups['A'][] = $extra;
    $state = $this->getJson('/monthly-weekend-pairs/2027/1')->json();
    $this->postJson('/monthly-weekend-pairs/2027/1', monthlyPairPayload($state, $groups))->assertOk();
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $extra, 'year' => 2027, 'month' => 2]);
    array_pop($groups['A']);
    $state = $this->getJson('/monthly-weekend-pairs/2027/2')->json();
    $this->postJson('/monthly-weekend-pairs/2027/2', monthlyPairPayload($state, $groups))->assertOk();
    $versions = WeekendPairConfiguration::query()->where('group_code', 'A')->orderBy('version')->get();
    expect($versions->pluck('mode')->map(fn ($mode): int => (int) $mode)->all())->toBe([6, 7, 6])
        ->and($versions->map(fn ($version): string => $version->pair_anchor_saturday->toDateString())->unique()->count())->toBe(1);
    expect(app(WeekendPairRotationService::class)->forSaturday('2027-01-02')['configuration_id'])->toBe($versions[1]->id)
        ->and(app(WeekendPairRotationService::class)->forSaturday('2026-12-19')['configuration_id'])->toBe($versions[0]->id)
        ->and(app(WeekendPairRotationService::class)->forSaturday('2026-12-19')['starting_pair'])->toBe(2)
        ->and(app(WeekendPairRotationService::class)->forSaturday('2027-01-02')['starting_pair'])->toBe(3);
});

it('marks a confirmation for attention when effective memberships change', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['A'][0], 'group_code' => 'B', 'effective_from_saturday' => '2026-12-19']);
    $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'requires_attention');
});

it('reads an existing roster snapshot rather than changed active statuses', function () {
    $groups = monthlyPairFixture();
    $roster = Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => 'draft']);
    snapshotRosterParticipation($roster);
    Doctor::query()->whereKey($groups['A'][0])->update(['is_active' => false]);
    $response = $this->actingAs(User::factory()->create())->getJson('/monthly-weekend-pairs/2026/12')
        ->assertOk()->assertJsonPath('read_only', true)->json();
    expect(array_column($response['groups']['A']['doctors'], 'id'))->toContain($groups['A'][0]);
});

it('uses saved participation for draft and final roster eligibility', function (string $status) {
    $groups = monthlyPairFixture();
    $roster = Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => 'draft']);
    snapshotRosterParticipation($roster);
    $roster->update(['status' => $status]);
    Doctor::query()->whereKey($groups['A'][0])->update(['is_active' => false]);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['B'][0], 'year' => 2026, 'month' => 12]);
    $response = $this->actingAs(User::factory()->create())->getJson('/monthly-weekend-pairs/2026/12')
        ->assertJsonPath('read_only', true)->json();
    expect(array_column($response['groups']['A']['doctors'], 'id'))->toContain($groups['A'][0])
        ->and(array_column($response['groups']['B']['doctors'], 'id'))->not->toContain($groups['B'][0]);
    $this->assertDatabaseHas('doctor_monthly_participations', ['doctor_id' => $groups['A'][0], 'roster_id' => $roster->id, 'is_participating' => true]);
})->with(['draft', 'final']);

it('reports a damaged historical snapshot without reconstructing participation', function () {
    $groups = monthlyPairFixture();
    $roster = Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => 'draft']);
    snapshotRosterParticipation($roster);
    $roster->update(['status' => 'final']);
    DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->where('doctor_id', $groups['A'][0])->delete();
    $count = DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->count();
    $this->actingAs(User::factory()->create())->getJson('/monthly-weekend-pairs/2026/12')
        ->assertJsonPath('status', 'requires_attention')
        ->assertJsonPath('issues.0', 'This roster has incomplete or mismatched participation history. Resolve the monthly participation records before continuing.');
    expect(DoctorMonthlyParticipation::query()->where('roster_id', $roster->id)->count())->toBe($count);
    $this->assertDatabaseMissing('doctor_monthly_participations', ['roster_id' => $roster->id, 'doctor_id' => $groups['A'][0]]);
});

it('leaves participation records for other months unchanged', function () {
    $groups = monthlyPairFixture();
    $record = DoctorMonthlyParticipation::query()->create(['doctor_id' => $groups['A'][0], 'year' => 2026, 'month' => 11, 'is_participating' => false]);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'not_configured')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertJsonPath('status', 'confirmed');
    $this->assertDatabaseHas('doctor_monthly_participations', ['id' => $record->id, 'month' => 11, 'roster_id' => null, 'is_participating' => false]);
});

it('requires attention for a Sunday boundary without a historical configuration', function () {
    $groups = monthlyPairFixture();
    $admin = User::factory()->create();
    app(WeekendPairConfigurationService::class)->create([
        'group_code' => 'B', 'effective_saturday' => '2026-12-12', 'mode' => 6,
        'doctor_ids' => $groups['B'], 'pair_anchor_phase' => 0, 'extra_day_anchor_phase' => 0,
    ], $admin->id);
    $this->actingAs(User::factory()->create())->getJson('/monthly-weekend-pairs/2027/8')
        ->assertOk()->assertJsonPath('boundary.saturday', '2027-07-31')
        ->assertJsonPath('boundary.state', 'pair_rotation')->assertJsonPath('boundary.compatible', false)
        ->assertJsonPath('status', 'requires_attention');
});

it('confirms the first pair enabled November without backdating the legacy weekend', function () {
    WeekendRotationConfiguration::query()->create(['anchor_saturday' => '2026-11-07', 'anchor_group' => 'B']);
    $groups = [];
    foreach (['A', 'B'] as $group) {
        for ($i = 1; $i <= 6; $i++) {
            $doctor = Doctor::query()->create(['name' => "Doctor {$group}{$i}", 'short_code' => "{$group}{$i}", 'is_active' => true]);
            DoctorWeekendGroupMembership::query()->create(['doctor_id' => $doctor->id, 'group_code' => $group, 'effective_from_saturday' => '2026-11-07']);
            $groups[$group][] = $doctor->id;
        }
    }
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/11')->assertJsonPath('boundary.state', 'legacy')
        ->assertJsonPath('boundary.saturday', '2026-10-31')->assertJsonPath('groups.A.effective_saturday', '2026-11-14')
        ->assertJsonPath('groups.B.effective_saturday', '2026-11-07')->json();
    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/11', monthlyPairPayload($state, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('boundary.state', 'legacy')
        ->assertJsonPath('confirmation.boundary_configuration_id', null)->json();
    expect($confirmed['population_fingerprint'])->toBe($state['population_fingerprint']);
    expect(WeekendPairConfiguration::query()->whereDate('effective_saturday', '<=', '2026-10-31')->exists())->toBeFalse();
    $rotation = app(WeekendPairRotationService::class);
    expect($rotation->forSaturday('2026-10-31'))->toBeNull()
        ->and($rotation->forSaturday('2026-11-07')['starting_pair'])->toBe(1)
        ->and($rotation->forSaturday('2026-11-14')['starting_pair'])->toBe(1);
    expect(WeekendPairConfiguration::query()->where('group_code', 'A')->firstOrFail()->pair_anchor_saturday->toDateString())->toBe('2026-11-14')
        ->and(WeekendPairConfiguration::query()->where('group_code', 'B')->firstOrFail()->pair_anchor_saturday->toDateString())->toBe('2026-11-07');

    $extra = Doctor::query()->create(['name' => 'Additional A', 'short_code' => 'A7', 'is_active' => true]);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $extra->id, 'group_code' => 'A', 'effective_from_saturday' => '2026-11-07']);
    $groups['A'][] = $extra->id;
    $state = $this->getJson('/monthly-weekend-pairs/2026/11')->json();
    $this->postJson('/monthly-weekend-pairs/2026/11', monthlyPairPayload($state, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('boundary.state', 'legacy')
        ->assertJsonPath('confirmation.boundary_configuration_id', null);
    expect($rotation->forSaturday('2026-10-31'))->toBeNull();
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
});

it('uses the historical configuration for a later Sunday boundary', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $august = $this->getJson('/monthly-weekend-pairs/2027/8')->assertJsonPath('boundary.state', 'pair_rotation')
        ->assertJsonPath('boundary.saturday', '2027-07-31')->assertJsonPath('boundary.compatible', true)->json();
    $boundaryId = $august['boundary']['configuration']['id'];
    $this->postJson('/monthly-weekend-pairs/2027/8', monthlyPairPayload($august, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('confirmation.boundary_configuration_id', $boundaryId);
    expect(WeekendPairConfiguration::query()->count())->toBe(2);

    $historical = app(WeekendPairRotationService::class)->forSaturday('2027-07-31');
    $extra = Doctor::query()->create(['name' => 'Additional B', 'short_code' => 'B7', 'is_active' => true]);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $extra->id, 'group_code' => 'B', 'effective_from_saturday' => '2027-07-31']);
    $groups['B'][] = $extra->id;
    $august = $this->getJson('/monthly-weekend-pairs/2027/8')->json();
    $this->postJson('/monthly-weekend-pairs/2027/8', monthlyPairPayload($august, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('boundary.compatible', true)
        ->assertJsonPath('confirmation.boundary_configuration_id', $boundaryId);
    expect(app(WeekendPairRotationService::class)->forSaturday('2027-07-31'))->toBe($historical);
});

it('flags incompatible historical membership without rewriting the earlier configuration', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $historical = app(WeekendPairRotationService::class)->forSaturday('2026-12-05');
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['A'][0], 'group_code' => 'B', 'effective_from_saturday' => '2027-07-31']);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $groups['A'][0], 'group_code' => 'A', 'effective_from_saturday' => '2027-08-07']);
    $this->getJson('/monthly-weekend-pairs/2027/8')->assertJsonPath('boundary.state', 'pair_rotation')
        ->assertJsonPath('boundary.compatible', false)->assertJsonPath('status', 'requires_attention');
    expect(app(WeekendPairRotationService::class)->forSaturday('2026-12-05'))->toBe($historical)
        ->and(WeekendPairConfiguration::query()->count())->toBe(2);
});

it('uses MySQL compatible names for the confirmation foreign keys', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Foreign key names are verified on MySQL.');
    }
    $foreignKeys = collect(Schema::getForeignKeys('monthly_weekend_pair_confirmations'))->pluck('name')->all();
    expect($foreignKeys)->toContain('mwpc_group_a_config_fk', 'mwpc_group_b_config_fk', 'mwpc_boundary_config_fk');
    foreach ($foreignKeys as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64);
    }
    foreach (Schema::getIndexes('monthly_weekend_pair_confirmations') as $index) {
        expect(strlen($index['name']))->toBeLessThanOrEqual(64);
    }
});

it('makes existing draft and final months read-only', function (string $status) {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => $status]);
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertUnprocessable();
    expect(WeekendPairConfiguration::query()->count())->toBe(0);
})->with(['draft', 'final']);

it('reports missing group membership without creating configuration', function () {
    monthlyPairFixture();
    DoctorWeekendGroupMembership::query()->where('group_code', 'A')->firstOrFail()->delete();
    $this->actingAs(User::factory()->create())->getJson('/monthly-weekend-pairs/2026/12')
        ->assertOk()->assertJsonPath('status', 'requires_attention');
    expect(WeekendPairConfiguration::query()->count())->toBe(0);
});

it('reconfirms exclusion changes with immutable history through seven six and seven doctor modes', function () {
    $groups = monthlyPairFixture(7, 6);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $initial = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail();
    $originalAttributes = $original->getAttributes();
    $configuration = WeekendPairConfiguration::query()->findOrFail($initial['confirmation']['configuration_ids']['A']);
    $configurationAttributes = $configuration->getAttributes();
    $originalDoctors = $configuration->doctors->pluck('doctor_id')->all();
    $excludedId = array_pop($groups['A']);
    $exclusion = DoctorMonthlyExclusion::query()->create(['doctor_id' => $excludedId, 'year' => 2026, 'month' => 12]);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'requires_attention')->json();

    $corrected = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('groups.A.configuration.mode', 6)->json();

    $this->assertDatabaseHas('monthly_weekend_pair_confirmations', [
        'id' => $corrected['confirmation']['id'], 'supersedes_confirmation_id' => $original->id,
        'group_b_configuration_id' => $original->group_b_configuration_id,
    ]);
    expect($original->fresh()->getAttributes())->toBe($originalAttributes);
    expect($configuration->fresh()->getAttributes())->toBe($configurationAttributes);
    expect($configuration->fresh()->doctors->pluck('doctor_id')->all())->toBe($originalDoctors);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);

    $repeat = monthlyPairPayload($corrected, $groups);
    $this->postJson('/monthly-weekend-pairs/2026/12', $repeat)->assertExactJson($corrected);
    $this->postJson('/monthly-weekend-pairs/2026/12', $repeat)->assertExactJson($corrected);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);

    $exclusion->delete();
    $groups['A'][] = $excludedId;
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $restored = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('groups.A.configuration.mode', 7)->json();

    $this->assertDatabaseHas('monthly_weekend_pair_confirmations', [
        'id' => $restored['confirmation']['id'], 'supersedes_confirmation_id' => $corrected['confirmation']['id'],
    ]);
    $versions = WeekendPairConfiguration::query()->where('group_code', 'A')->orderBy('version')->get();
    expect($versions->pluck('mode')->map(fn ($mode): int => (int) $mode)->all())->toBe([7, 6, 7]);
    expect($versions->pluck('version')->map(fn ($version): int => (int) $version)->all())->toBe([1, 2, 3]);
    expect($versions->map(fn ($version): string => $version->pair_anchor_saturday->toDateString())->unique()->all())->toBe(['2026-12-05']);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 3);
});

it('reconfirms group membership changes without resetting Phase 1 rotation phases', function () {
    $groups = monthlyPairFixture();
    $admin = User::factory()->create();
    foreach (['A' => '2026-12-05', 'B' => '2026-12-12'] as $group => $date) {
        app(WeekendPairConfigurationService::class)->create([
            'group_code' => $group, 'effective_saturday' => $date, 'mode' => 6, 'doctor_ids' => $groups[$group],
            'pair_anchor_phase' => 2, 'extra_day_anchor_phase' => 4,
        ], $admin->id);
    }
    $this->actingAs($admin);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $rotation = app(WeekendPairRotationService::class);
    $before = $rotation->forSaturday('2026-12-19');
    $a = $groups['A'][0];
    $b = $groups['B'][0];
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $a, 'group_code' => 'B', 'effective_from_saturday' => '2026-12-05']);
    DoctorWeekendGroupMembership::query()->create(['doctor_id' => $b, 'group_code' => 'A', 'effective_from_saturday' => '2026-12-05']);
    $groups['A'][0] = $b;
    $groups['B'][0] = $a;
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->assertJsonPath('status', 'requires_attention')->json();

    $corrected = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->json();

    $after = $rotation->forSaturday('2026-12-19');
    expect($after['group_occurrence'])->toBe(1);
    expect($after['starting_pair'])->toBe(1);
    expect($after['starting_pair'])->toBe($before['starting_pair']);
    expect($after['configuration_id'])->toBe($corrected['confirmation']['configuration_ids']['A']);
    expect($after['shifts']['saturday_day'])->toBe([$b, $groups['A'][1]]);
    expect($after['shifts']['sunday_night'])->toBe($after['shifts']['saturday_day']);
    foreach (['A', 'B'] as $group) {
        $this->assertDatabaseHas('weekend_pair_configurations', [
            'id' => $corrected['confirmation']['configuration_ids'][$group],
            'pair_anchor_phase' => 2, 'extra_day_anchor_phase' => 4, 'version' => 2,
        ]);
    }
    $this->assertDatabaseCount('weekend_pair_configurations', 4);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
});

it('rejects a competing stale reconfirmation with 422 after the first correction wins', function () {
    $groups = monthlyPairFixture(7, 6);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $initial = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->json();
    $excluded = array_pop($groups['A']);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $excluded, 'year' => 2026, 'month' => 12]);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $groups);
    $competitor = $payload;
    $competitor['groups']['A']['doctor_ids'] = array_reverse($groups['A']);
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($initial, $groups))
        ->assertUnprocessable()->assertJsonValidationErrors('revision');
    $winner = $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertOk()->json();

    $this->postJson('/monthly-weekend-pairs/2026/12', $competitor)->assertUnprocessable()
        ->assertJsonPath('errors.revision.0', 'The monthly configuration changed. Reload before confirming.');

    $this->getJson('/monthly-weekend-pairs/2026/12')->assertExactJson($winner);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);
});

it('rolls back both correction versions if persisting the new confirmation fails', function () {
    $groups = monthlyPairFixture(7, 7);
    $admin = User::factory()->create();
    $this->actingAs($admin);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    foreach (['A', 'B'] as $group) {
        $excluded = array_pop($groups[$group]);
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $excluded, 'year' => 2026, 'month' => 12]);
    }
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $observedVersions = null;
    $event = 'eloquent.creating: '.MonthlyWeekendPairConfirmation::class;
    Event::listen($event, function () use (&$observedVersions): void {
        $observedVersions = WeekendPairConfiguration::query()->count();
        throw ValidationException::withMessages(['configuration' => 'Confirmation persistence failed.']);
    });

    try {
        $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
            ->assertUnprocessable()->assertJsonValidationErrors('configuration');
    } finally {
        Event::forget($event);
    }

    expect($observedVersions)->toBe(4);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 14);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original);
    $this->getJson('/monthly-weekend-pairs/2026/12')->assertExactJson($state);
});

it('rejects reconfirmation with 422 after a draft or final roster is created', function (string $status) {
    $groups = monthlyPairFixture(7, 6);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    $excluded = array_pop($groups['A']);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $excluded, 'year' => 2026, 'month' => 12]);
    $roster = Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => 'draft']);
    snapshotRosterParticipation($roster);
    $roster->update(['status' => $status]);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();

    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertUnprocessable()->assertJsonValidationErrors('roster');

    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
})->with(['draft', 'final']);

it('holds the shared MySQL coordination lock throughout reconfirmation persistence', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL row-lock contention requires two MySQL connections.');
    }
    $groups = monthlyPairFixture(7, 6);
    $admin = User::factory()->create();
    $service = app(MonthlyWeekendPairService::class);
    $service->confirm(2026, 12, monthlyPairPayload($service->load(2026, 12), $groups), $admin->id);
    $excluded = array_pop($groups['A']);
    DoctorMonthlyExclusion::query()->create(['doctor_id' => $excluded, 'year' => 2026, 'month' => 12]);
    $payload = monthlyPairPayload($service->load(2026, 12), $groups);
    $connectionName = DB::getDefaultConnection();
    config(['database.connections.weekend_reconfirm_probe' => config("database.connections.{$connectionName}")]);
    $probe = DB::connection('weekend_reconfirm_probe');
    $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $lockError = null;
    $event = 'eloquent.creating: '.MonthlyWeekendPairConfirmation::class;
    Event::listen($event, function () use ($probe, &$lockError): void {
        $probe->beginTransaction();
        try {
            $probe->table('weekend_pair_coordination')->where('id', 1)->update(['id' => DB::raw('id')]);
        } catch (QueryException $exception) {
            $lockError = $exception;
        } finally {
            $probe->rollBack();
        }
    });

    try {
        $result = $service->confirm(2026, 12, $payload, $admin->id);
        expect($result['status'])->toBe('confirmed');
        expect($lockError)->toBeInstanceOf(QueryException::class);
        expect((int) $lockError->errorInfo[1])->toBe(1205);
        $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 2);
    } finally {
        Event::forget($event);
        DB::purge('weekend_reconfirm_probe');
    }
});

it('reloads eligibility and rejects a changed revision after acquiring the reconfirmation lock', function () {
    $groups = monthlyPairFixture(7, 6);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    $armed = true;
    $observedTransactionLevel = null;
    DB::listen(function ($query) use (&$armed, &$observedTransactionLevel, $groups): void {
        if ($armed && str_contains(strtolower($query->sql), 'update') && str_contains($query->sql, 'weekend_pair_coordination')) {
            $armed = false;
            $observedTransactionLevel = DB::transactionLevel();
            DoctorMonthlyExclusion::query()->create(['doctor_id' => $groups['A'][6], 'year' => 2026, 'month' => 12]);
        }
    });

    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($confirmed, $groups))
        ->assertUnprocessable()->assertJsonValidationErrors('revision');

    expect($armed)->toBeFalse();
    expect($observedTransactionLevel)->toBeGreaterThan(0);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
});

it('rejects invalid corrected groups with 422 without appending any versions', function (string $case) {
    $groups = monthlyPairFixture(7, 7);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    foreach (['A', 'B'] as $group) {
        $excluded = array_pop($groups[$group]);
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $excluded, 'year' => 2026, 'month' => 12]);
    }
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $payload = monthlyPairPayload($state, $groups);
    if ($case === 'wrong doctor') {
        $payload['groups']['B']['doctor_ids'][0] = $groups['A'][0];
    } else {
        $payload['groups']['B']['effective_saturday'] = '2026-12-26';
    }

    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($case === 'wrong doctor'
            ? ['groups.A.doctor_ids.0', 'groups.B.doctor_ids.0']
            : ['groups.B.effective_saturday']);

    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
})->with(['wrong doctor', 'wrong date']);

it('rolls back the first group correction when a later version prevents correcting the second group', function () {
    $groups = monthlyPairFixture(7, 7);
    $admin = User::factory()->create();
    $this->actingAs($admin);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    app(WeekendPairConfigurationService::class)->create([
        'group_code' => 'B', 'effective_saturday' => '2027-01-09', 'mode' => 7, 'doctor_ids' => $groups['B'],
        'pair_anchor_phase' => 0, 'extra_day_anchor_phase' => 0,
    ], $admin->id);
    foreach (['A', 'B'] as $group) {
        $excluded = array_pop($groups[$group]);
        DoctorMonthlyExclusion::query()->create(['doctor_id' => $excluded, 'year' => 2026, 'month' => 12]);
    }
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();

    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertUnprocessable()->assertJsonValidationErrors('effective_saturday');

    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 21);
    $this->getJson('/monthly-weekend-pairs/2026/12')->assertExactJson($state);
});

it('uses the newest reordered version while preserving the rotation anchor phases and historical positions', function () {
    $groups = monthlyPairFixture();
    $admin = User::factory()->create();
    $original = app(WeekendPairConfigurationService::class)->create([
        'group_code' => 'B', 'effective_saturday' => '2026-12-12', 'mode' => 6, 'doctor_ids' => $groups['B'],
        'pair_anchor_phase' => 2, 'extra_day_anchor_phase' => 4,
    ], $admin->id)->fresh();
    $originalAttributes = $original->getAttributes();
    $originalDoctors = $original->doctors->pluck('doctor_id')->all();
    $this->actingAs($admin);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    $rotation = app(WeekendPairRotationService::class);
    $before = $rotation->forSaturday('2026-12-26');
    [$groups['B'][1], $groups['B'][3]] = [$groups['B'][3], $groups['B'][1]];

    $corrected = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($confirmed, $groups))
        ->assertOk()->assertJsonPath('status', 'confirmed')->json();

    $after = $rotation->forSaturday('2026-12-26');
    expect($after['configuration_id'])->toBe($corrected['confirmation']['configuration_ids']['B']);
    expect($after['configuration_version'])->toBe(2);
    expect($after['group_occurrence'])->toBe($before['group_occurrence']);
    expect($after['starting_pair'])->toBe($before['starting_pair']);
    expect($after['shifts']['saturday_day'])->toBe([$groups['B'][0], $groups['B'][1]]);
    expect($after['shifts']['sunday_night'])->toBe([$groups['B'][0], $groups['B'][1]]);
    expect($after['shifts']['saturday_day'])->not->toBe($before['shifts']['saturday_day']);
    $this->assertDatabaseHas('weekend_pair_configurations', [
        'id' => $after['configuration_id'], 'pair_anchor_phase' => 2, 'extra_day_anchor_phase' => 4,
    ]);
    $newConfiguration = WeekendPairConfiguration::query()->findOrFail($after['configuration_id']);
    expect($newConfiguration->effective_saturday->toDateString())->toBe('2026-12-12');
    expect($newConfiguration->pair_anchor_saturday->toDateString())->toBe('2026-12-12');
    expect($original->fresh()->getAttributes())->toBe($originalAttributes);
    expect($original->fresh()->doctors->pluck('doctor_id')->all())->toBe($originalDoctors);
    $this->assertDatabaseCount('weekend_pair_configurations', 3);
});

it('rejects pair reordering with 422 when a draft or final roster exists', function (string $status) {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    $roster = Roster::query()->create(['year' => 2026, 'month' => 12, 'status' => 'draft']);
    snapshotRosterParticipation($roster);
    $roster->update(['status' => $status]);
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    [$groups['B'][1], $groups['B'][3]] = [$groups['B'][3], $groups['B'][1]];

    $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))
        ->assertUnprocessable()->assertJsonPath('errors.roster.0', 'A Draft or Final roster already exists for this month; pair configuration is read-only.');

    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
})->with(['draft', 'final']);

it('validates both selected cohorts before saving reordered pairs', function (string $case) {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    [$groups['A'][1], $groups['A'][3]] = [$groups['A'][3], $groups['A'][1]];
    $payload = monthlyPairPayload($confirmed, $groups);
    if ($case === 'wrong doctor') {
        $payload['groups']['B']['doctor_ids'][0] = $groups['A'][0];
    } else {
        $payload['groups']['B']['effective_saturday'] = '2026-12-26';
    }

    $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($case === 'wrong doctor'
            ? ['groups.A.doctor_ids.0', 'groups.B.doctor_ids.0']
            : ['groups.B.effective_saturday']);

    $this->getJson('/monthly-weekend-pairs/2026/12')->assertExactJson($confirmed);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
})->with(['wrong doctor', 'wrong date']);

it('rolls back reordered configuration versions when confirmation persistence fails', function () {
    $groups = monthlyPairFixture();
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    foreach (['A', 'B'] as $group) {
        [$groups[$group][1], $groups[$group][3]] = [$groups[$group][3], $groups[$group][1]];
    }
    $observedVersions = null;
    $event = 'eloquent.creating: '.MonthlyWeekendPairConfirmation::class;
    Event::listen($event, function () use (&$observedVersions): void {
        $observedVersions = WeekendPairConfiguration::query()->count();
        throw ValidationException::withMessages(['configuration' => 'Confirmation persistence failed.']);
    });

    try {
        $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($confirmed, $groups))
            ->assertUnprocessable()->assertJsonValidationErrors('configuration');
    } finally {
        Event::forget($event);
    }

    expect($observedVersions)->toBe(4);
    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 12);
    $this->getJson('/monthly-weekend-pairs/2026/12')->assertExactJson($confirmed);
});

it('rejects independently invalid pair populations without changing the confirmed history', function (string $case, array $errorKeys) {
    $groups = monthlyPairFixture(7, 7);
    $this->actingAs(User::factory()->create());
    $state = $this->getJson('/monthly-weekend-pairs/2026/12')->json();
    $confirmed = $this->postJson('/monthly-weekend-pairs/2026/12', monthlyPairPayload($state, $groups))->assertOk()->json();
    $original = MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes();
    $payload = monthlyPairPayload($confirmed, $groups);
    if ($case === 'duplicate within group') {
        $payload['groups']['B']['doctor_ids'][0] = $groups['B'][1];
    } elseif ($case === 'fewer than six selected doctors') {
        $payload['groups']['A']['doctor_ids'] = array_slice($groups['A'], 0, 5);
    } else {
        [$payload['groups']['A']['doctor_ids'][0], $payload['groups']['B']['doctor_ids'][0]]
            = [$groups['B'][0], $groups['A'][0]];
        $ids = array_merge($payload['groups']['A']['doctor_ids'], $payload['groups']['B']['doctor_ids']);
        expect(count(array_unique($ids)))->toBe(14);
        expect(count($payload['groups']['A']['doctor_ids']))->toBe(7);
        expect(count($payload['groups']['B']['doctor_ids']))->toBe(7);
    }

    $response = $this->postJson('/monthly-weekend-pairs/2026/12', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($errorKeys);

    expect(array_keys($response->json('errors')))->toBe($errorKeys);
    if ($case === 'wrong membership without duplicates') {
        expect($response->json('errors')['groups.A.doctor_ids'])->toBe(['Select six or seven distinct eligible Group A doctors in rotation order.']);
    }
    expect(MonthlyWeekendPairConfirmation::query()->firstOrFail()->getAttributes())->toBe($original);
    $this->getJson('/monthly-weekend-pairs/2026/12')->assertExactJson($confirmed);
    $this->assertDatabaseCount('monthly_weekend_pair_confirmations', 1);
    $this->assertDatabaseCount('weekend_pair_configurations', 2);
    $this->assertDatabaseCount('weekend_pair_configuration_doctors', 14);
})->with([
    'duplicate within group' => ['duplicate within group', ['groups.B.doctor_ids.0', 'groups.B.doctor_ids.1']],
    'fewer than six selected doctors' => ['fewer than six selected doctors', ['groups.A.doctor_ids']],
    'wrong membership without duplicates' => ['wrong membership without duplicates', ['groups.A.doctor_ids']],
]);
