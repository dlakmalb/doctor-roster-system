<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\DoctorRequestType;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\Roster;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorMonthlyParticipationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function seedMonthlySetupBaselineHistory(int $year, int $month): void
{
    $participation = app(DoctorMonthlyParticipationService::class);

    foreach (Doctor::query()->get() as $doctor) {
        DoctorMonthlyWorkload::create([
            'doctor_id' => $doctor->id,
            'year' => $year,
            'month' => $month,
            'source' => DoctorMonthlyWorkloadSource::ManualInitial,
            'actual_worked_minutes' => 0,
        ]);
        $participation->saveBaseline($doctor->id, $year, $month, true);
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
});

it('renders monthly setup without creating a roster', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('monthly-setup')
            ->where('month.label', 'October 2026')
            ->where('rosterStatus', 'not_started')
            ->where('summary.active_doctors', 14)
            ->where('summary.total_requests', 0)
            ->has('dayOffRequests', 0)
            ->has('preferredWorkRequests', 0)
            ->has('exclusions', 0)
            ->has('requests', 0));

    $this->assertDatabaseCount('rosters', 0);
});

it('shows only requests belonging to the selected month', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $doctor = Doctor::firstOrFail();
    $shiftType = ShiftType::where('code', 'weekday_day')->firstOrFail();
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05']);
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::PreferredWork, 'request_date' => '2026-10-06', 'shift_type_id' => $shiftType->id]);
    DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-11-05']);

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('dayOffRequests', 1)
            ->where('dayOffRequests.0.request_date', '2026-10-05')
            ->has('preferredWorkRequests', 1)
            ->where('preferredWorkRequests.0.request_date', '2026-10-06'));
});

it('maps the existing request and exclusion records into one display list', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00:00'));
    $doctor = Doctor::firstOrFail();
    $dayShift = ShiftType::where('code', 'weekday_day')->firstOrFail();
    $offRequest = DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => '2026-10-05',
    ]);
    $preferredWork = DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => '2026-10-06',
        'shift_type_id' => $dayShift->id,
    ]);
    $exclusion = DoctorMonthlyExclusion::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'note' => 'On leave',
    ]);
    $offRequest->forceFill(['created_at' => '2026-09-21 09:00:00'])->save();

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_requests', 3)
            ->where('requests', fn (Collection $requests): bool => $requests->contains(fn (array $request): bool => $request['id'] === $offRequest->id
                && $request['kind'] === 'off_request'
                && $request['shift_label'] === 'Full Day'
                && $request['is_late'])
                && $requests->contains(fn (array $request): bool => $request['id'] === $preferredWork->id
                && $request['kind'] === 'preferred_work'
                    && $request['shift_label'] === 'Day')
                && $requests->contains(fn (array $request): bool => $request['id'] === $exclusion->id
                    && $request['kind'] === 'monthly_exclusion'
                    && $request['date_label'] === 'October 2026'
                    && $request['shift_label'] === null)));

    $this->assertDatabaseCount('doctor_requests', 2);
    $this->assertDatabaseCount('doctor_monthly_exclusions', 1);
    expect($offRequest->fresh()->request_type)->toBe(DoctorRequestType::DayOff)
        ->and($preferredWork->fresh()->request_type)->toBe(DoctorRequestType::PreferredWork);
});

it('warns only on the fourth and later distinct Off Request dates', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    $doctor = Doctor::firstOrFail();
    $shiftTypes = ShiftType::query()->get()->keyBy('code');
    $createOffRequest = fn (string $date, string $shiftCode): DoctorRequest => DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::DayOff,
        'request_date' => $date,
        'shift_type_id' => $shiftTypes->get($shiftCode)->id,
    ]);

    $octoberSecondDay = $createOffRequest('2026-10-02', 'weekday_day');
    $octoberSecondLate = $createOffRequest('2026-10-02', 'weekday_evening');
    $octoberFifth = $createOffRequest('2026-10-05', 'weekday_day');
    $octoberTenth = $createOffRequest('2026-10-10', 'weekend_day');
    $octoberFourteenthLate = $createOffRequest('2026-10-14', 'weekday_day');
    $octoberFourteenthEvening = $createOffRequest('2026-10-14', 'weekday_evening');
    $octoberTwentieth = $createOffRequest('2026-10-20', 'weekday_day');
    $octoberSecondLate->forceFill(['created_at' => '2026-09-21 09:00:00'])->save();
    $octoberFourteenthLate->forceFill(['created_at' => '2026-09-21 09:00:00'])->save();
    $preferredWork = DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => '2026-10-01',
        'shift_type_id' => $shiftTypes->get('weekday_day')->id,
    ]);
    $exclusion = DoctorMonthlyExclusion::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests', function (Collection $requests) use (
                $octoberSecondDay,
                $octoberSecondLate,
                $octoberFifth,
                $octoberTenth,
                $octoberFourteenthLate,
                $octoberFourteenthEvening,
                $octoberTwentieth,
                $preferredWork,
                $exclusion,
            ): bool {
                $items = $requests->mapWithKeys(fn (array $item): array => [
                    $item['kind'].'-'.$item['id'] => $item,
                ]);

                return $items['off_request-'.$octoberSecondDay->id]['has_date_limit_warning'] === false
                    && $items['off_request-'.$octoberSecondLate->id]['has_date_limit_warning'] === false
                    && $items['off_request-'.$octoberSecondLate->id]['is_late'] === true
                    && $items['off_request-'.$octoberFifth->id]['has_date_limit_warning'] === false
                    && $items['off_request-'.$octoberTenth->id]['has_date_limit_warning'] === false
                    && $items['off_request-'.$octoberFourteenthLate->id]['has_date_limit_warning'] === true
                    && $items['off_request-'.$octoberFourteenthLate->id]['is_late'] === true
                    && $items['off_request-'.$octoberFourteenthEvening->id]['has_date_limit_warning'] === true
                    && $items['off_request-'.$octoberTwentieth->id]['has_date_limit_warning'] === true
                    && $items['preferred_work-'.$preferredWork->id]['has_date_limit_warning'] === false
                    && $items['monthly_exclusion-'.$exclusion->id]['has_date_limit_warning'] === false;
            }));
});

it('keeps historical requests visible when a doctor becomes inactive', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    seedInitialHistoryForGeneration();
    $doctor = Doctor::firstOrFail();
    $request = DoctorRequest::create(['doctor_id' => $doctor->id, 'request_type' => DoctorRequestType::DayOff, 'request_date' => '2026-10-05']);
    $doctor->update(['is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dayOffRequests.0.id', $request->id)
            ->where('dayOffRequests.0.doctor.is_active', false)
            ->has('doctors', 13));
});

it('rejects invalid month route values', function () {
    $this->actingAs(User::factory()->create())
        ->get('/monthly-setup/2026/13')
        ->assertNotFound();

    $this->get('/monthly-setup/0000/10')->assertNotFound();
});

it('shows current and next month roster statuses without creating rosters', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
    Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('months.0.label', 'October 2026')
            ->where('months.0.status', 'not_started')
            ->where('months.1.label', 'November 2026')
            ->where('months.1.status', 'final'));

    $this->assertDatabaseCount('rosters', 1);
});

it('shows one global Initial Setup action when the manual baseline is missing', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialSetup.required', true)
            ->where('initialSetup.month.label', 'October 2026')
            ->where('primaryMonth.label', 'November 2026')
            ->where('secondaryMonth', null));
});

it('makes next month primary after the current baseline exists', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
    seedMonthlySetupBaselineHistory(2026, 10);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialSetup.required', false)
            ->where('primaryMonth.label', 'November 2026')
            ->where('primaryMonth.status', 'not_started')
            ->where('secondaryMonth', null));
});

it('keeps next month primary after finalizing the current month', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
    seedMonthlySetupBaselineHistory(2026, 9);
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final]);
    snapshotRosterParticipation($october, Doctor::query()->get());

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialSetup.required', false)
            ->where('initialSetup.month.label', 'September 2026')
            ->where('primaryMonth.label', 'November 2026')
            ->where('secondaryMonth.label', 'October 2026')
            ->where('secondaryMonth.status', 'final'));
});

it('surfaces unconfirmed previous actual work without marking it confirmed', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
    seedMonthlySetupBaselineHistory(2026, 9);
    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final]);
    snapshotRosterParticipation($october, Doctor::query()->get());

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primaryMonth.label', 'November 2026')
            ->where('primaryMonth.history_readiness.ready', true)
            ->where('primaryMonth.history_readiness.basis', 'final_planned')
            ->where('primaryMonth.history_readiness.action', 'review')
            ->where('primaryMonth.history_readiness.message', 'Using finalized October 2026 roster history until actual work is confirmed.'));

    expect($october->fresh()->actual_work_confirmed_at)->toBeNull();
});

it('keeps November as the October planning month even after November is Final', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
    Doctor::query()->get()->each(fn (Doctor $doctor) => DoctorMonthlyWorkload::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 10,
        'source' => DoctorMonthlyWorkloadSource::ManualInitial,
        'actual_worked_minutes' => 0,
    ]));
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final]);
    snapshotRosterParticipation($november, Doctor::query()->get());
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primaryMonth.label', 'November 2026')
            ->where('primaryMonth.status', 'final')
            ->where('secondaryMonth', null));

    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primaryMonth.label', 'December 2026')
            ->where('secondaryMonth.label', 'November 2026'));
});
