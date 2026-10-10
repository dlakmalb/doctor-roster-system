<?php

use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\User;
use App\Services\RosterPlanningPeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app()->instance('testing.original_application_environment', app()->environment());
});

afterEach(function (): void {
    $originalEnvironment = app()->make('testing.original_application_environment');
    app()->detectEnvironment(fn (): string => $originalEnvironment);
    app()->forgetInstance('testing.original_application_environment');
});

function detectApplicationEnvironment(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);

    expect(app()->environment())->toBe($environment);
}

function planningOverridePayload($doctors): array
{
    return ['doctors' => $doctors->map(fn (Doctor $doctor): array => [
        'doctor_id' => $doctor->id,
        'participation_status' => 'participating',
        'actual_hours' => '0',
        'actual_night_duty_count' => 0,
        'optional_assignment_count' => 0,
        'worked_final_weekend' => false,
        'most_recent_night_shift_at' => null,
    ])->all()];
}

it('uses the real current month when the local override is disabled or absent', function (mixed $configuredMonth) {
    test()->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00'));
    detectApplicationEnvironment('local');
    config(['roster.uat_operational_month' => $configuredMonth]);

    expect(app(RosterPlanningPeriodService::class)->operationalMonth()->format('Y-m'))->toBe('2026-10')
        ->and(app(RosterPlanningPeriodService::class)->planningMonth()->format('Y-m'))->toBe('2026-11');
})->with([null, false, '']);

it('uses the local historical month while preserving initial setup and planning restrictions', function () {
    test()->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00'));
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    detectApplicationEnvironment('local');
    config(['roster.uat_operational_month' => '2026-09']);
    $admin = User::factory()->create();
    $doctors = Doctor::query()->orderBy('id')->get();

    expect(app(RosterPlanningPeriodService::class)->operationalMonth()->format('Y-m'))->toBe('2026-09')
        ->and(app(RosterPlanningPeriodService::class)->planningMonth()->format('Y-m'))->toBe('2026-10');

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('months.0.label', 'September 2026')
            ->where('months.1.label', 'October 2026')
            ->where('initialSetup.month.label', 'September 2026')
            ->where('primaryMonth.label', 'October 2026'));

    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertRedirect(route('initial-workload.show', ['year' => 2026, 'month' => 9]));
    $this->get(route('initial-workload.show', ['year' => 2026, 'month' => 9]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('initial-workload-setup')
            ->where('month.label', 'September 2026'));

    $this->withHeader('Sec-Fetch-Site', 'same-origin')
        ->post(route('initial-workload.save', ['year' => 2026, 'month' => 9]), planningOverridePayload($doctors))
        ->assertRedirect(route('initial-workload.show', ['year' => 2026, 'month' => 9]));
    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('monthly-setup')->where('month.label', 'October 2026'));
    $this->withHeader('Sec-Fetch-Site', 'same-origin')
        ->post(route('rosters.store', ['year' => 2026, 'month' => 10]))
        ->assertRedirect(route('rosters.show', ['year' => 2026, 'month' => 10]));
    $this->assertDatabaseHas('rosters', ['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft->value]);

    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 11]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Monthly Setup is currently available for October 2026.');
    $this->withHeader('Sec-Fetch-Site', 'same-origin')
        ->post(route('rosters.store', ['year' => 2026, 'month' => 11]))
        ->assertRedirect(route('dashboard'));
    $this->assertDatabaseCount('rosters', 1);
});

it('ignores the configured override outside the local environment', function (string $environment) {
    test()->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00'));
    detectApplicationEnvironment($environment);
    config(['roster.uat_operational_month' => '2026-09']);

    expect(app(RosterPlanningPeriodService::class)->operationalMonth()->format('Y-m'))->toBe('2026-10')
        ->and(app(RosterPlanningPeriodService::class)->planningMonth()->format('Y-m'))->toBe('2026-11');
})->with(['production', 'staging']);

it('rejects invalid local override values instead of selecting an unintended month', function (mixed $configuredMonth) {
    detectApplicationEnvironment('local');
    config(['roster.uat_operational_month' => $configuredMonth]);

    expect(fn () => app(RosterPlanningPeriodService::class)->operationalMonth())
        ->toThrow(InvalidArgumentException::class, 'ROSTER_UAT_OPERATIONAL_MONTH must use a valid YYYY-MM month');
})->with(['2026-9', '2026-13', '0000-09', 'not-a-month', 202609]);

it('keeps default first time setup and roster creation guards active without an override', function () {
    test()->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00'));
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    config(['roster.uat_operational_month' => null]);
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(route('monthly-setup.show', ['year' => 2026, 'month' => 11]))
        ->assertRedirect(route('initial-workload.show', ['year' => 2026, 'month' => 10]));
    $this->post(route('rosters.store', ['year' => 2026, 'month' => 10]))
        ->assertRedirect(route('dashboard'));
    $this->assertDatabaseCount('rosters', 0);
});
