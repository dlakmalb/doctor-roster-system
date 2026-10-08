<?php

use App\Enums\DoctorMonthlyWorkloadSource;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyWorkload;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RosterDocumentService;
use App\Services\RosterDraftValidationService;
use App\Services\RosterHistoryReadinessService;
use App\Services\RosterStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function workflowUrl(string $name, int $year, int $month, array $extra = []): string
{
    return route($name, ['year' => $year, 'month' => $month, ...$extra]);
}

function workflowBaseline($doctors): array
{
    return ['doctors' => $doctors->map(fn (Doctor $doctor): array => [
        'doctor_id' => $doctor->id,
        'participation_status' => $doctor->is_active ? 'participating' : 'not_part_of_team',
        'actual_hours' => '0',
        'actual_night_duty_count' => 0,
        'optional_assignment_count' => 0,
        'worked_final_weekend' => false,
        'most_recent_night_shift_at' => null,
    ])->all()];
}

it('connects setup, generation, editing, finalization, actual work, documents, and next month history', function () {
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    for ($number = 15; $number <= 24; $number++) {
        Doctor::create(['name' => "Doctor $number", 'short_code' => "D$number", 'is_active' => true]);
    }
    $admin = User::factory()->create();
    $doctors = Doctor::query()->orderByDesc('is_active')->orderBy('short_code')->get();
    $activeDoctors = $doctors->where('is_active', true)->values();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    $this->actingAs($admin);

    $this->post(workflowUrl('rosters.store', 2026, 10))
        ->assertRedirect(route('initial-workload.show', ['year' => 2026, 'month' => 9]))
        ->assertSessionHas('status', 'Complete Initial Setup for September 2026 before creating a October 2026 Draft roster.');
    $this->assertDatabaseCount('rosters', 0);
    expect(app(RosterHistoryReadinessService::class)->forMonth(2026, 10)['action'])->toBe('initial_setup');
    $this->post(workflowUrl('initial-workload.save', 2026, 9), workflowBaseline($doctors))->assertRedirect();
    expect(DoctorMonthlyWorkload::query()->where('year', 2026)->where('month', 9)
        ->where('source', DoctorMonthlyWorkloadSource::ManualInitial)->count())->toBe($doctors->count());

    $this->post(workflowUrl('rosters.store', 2026, 10))->assertRedirect();
    $october = Roster::query()->where('year', 2026)->where('month', 10)->firstOrFail();

    $dayType = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $this->post(workflowUrl('doctor-requests.store', 2026, 10), [
        'doctor_id' => $doctors[0]->id, 'request_type' => 'day_off', 'request_date' => '2026-10-06',
    ])->assertRedirect();
    $this->post(workflowUrl('doctor-requests.store', 2026, 10), [
        'doctor_id' => $doctors[1]->id, 'request_type' => 'preferred_work',
        'request_date' => '2026-10-01', 'shift_type_id' => $dayType->id,
    ])->assertRedirect();
    $this->post(workflowUrl('monthly-exclusions.store', 2026, 10), ['doctor_id' => $activeDoctors[2]->id])->assertRedirect();

    $this->post(workflowUrl('rosters.generate', 2026, 10))->assertRedirect();
    $assignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->get();
    expect($assignments->count())->toBeGreaterThan(0)
        ->and($assignments->contains('doctor_id', $activeDoctors[2]->id))->toBeFalse();
    $dayOffShiftIds = $october->shifts()->whereDate('shift_date', '2026-10-06')->pluck('id');
    expect($assignments->where('doctor_id', $doctors[0]->id)->whereIn('roster_shift_id', $dayOffShiftIds)->isEmpty())->toBeTrue();
    $preferredShift = $october->shifts()->whereDate('shift_date', '2026-10-01')->where('shift_type_id', $dayType->id)->firstOrFail();
    expect($assignments->contains(fn (RosterAssignment $assignment): bool => $assignment->roster_shift_id === $preferredShift->id
        && $assignment->doctor_id === $doctors[1]->id && $assignment->role === RosterAssignmentRole::Main))->toBeTrue();

    $editable = $assignments->first(fn (RosterAssignment $assignment): bool => $assignment->roster_shift_id === $preferredShift->id
        && $assignment->role === RosterAssignmentRole::Optional);
    $generatedAt = $october->fresh()->last_generated_at;
    $this->postJson(workflowUrl('rosters.assignments.edit', 2026, 10), [
        'operation' => 'clear', 'shift_id' => $editable->roster_shift_id, 'role' => 'optional',
        'slot_number' => $editable->slot_number, 'expected_assignment_id' => $editable->id,
        'expected_doctor_id' => $editable->doctor_id,
    ])->assertJsonPath('status', 'saved');
    expect($editable->fresh())->toBeNull();
    $this->postJson(workflowUrl('rosters.assignments.undo', 2026, 10))->assertJsonPath('status', 'saved');
    expect(RosterAssignment::query()->where('roster_shift_id', $editable->roster_shift_id)
        ->where('role', RosterAssignmentRole::Optional)->where('slot_number', $editable->slot_number)
        ->value('doctor_id'))->toBe($editable->doctor_id);
    expect($october->fresh()->last_generated_at?->equalTo($generatedAt))->toBeTrue();

    $issues = app(RosterDraftValidationService::class)->validate($october->fresh());
    expect(collect($issues)->where('severity', 'Error')->isEmpty())->toBeTrue();
    $this->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (Assert $page) => $page->component('roster')
            ->where('has_generated', true)
            ->where('conflicts', fn (Collection $items): bool => $items->where('severity', 'Error')->isEmpty()));
    $planned = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->orderBy('id')->pluck('doctor_id', 'id')->all();
    $finalize = $this->postJson(workflowUrl('rosters.finalize', 2026, 10))->assertOk();
    if ($finalize->json('status') === 'confirmation_required') {
        $this->postJson(workflowUrl('rosters.finalize', 2026, 10), ['warning_signature' => $finalize->json('warning_signature')])
            ->assertJsonPath('status', 'finalized');
    } else {
        expect($finalize->json('status'))->toBe('finalized');
    }
    expect($october->fresh()->status)->toBe(RosterStatus::Final)
        ->and($october->fresh()->finalized_by)->toBe($admin->id);
    expect(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->orderBy('id')->pluck('doctor_id', 'id')->all())->toBe($planned);

    $document = app(RosterDocumentService::class)->build($october->fresh());
    expect($document['status'])->toBe('final')->and($document['is_draft'])->toBeFalse()
        ->and(count($document['rows']))->toBe($october->shifts()->count());
    $pdf = $this->get(workflowUrl('rosters.pdf', 2026, 10))->assertOk();
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $finalAssignments = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->get();
    $mainAbsent = $finalAssignments->first(fn (RosterAssignment $assignment): bool => $assignment->role === RosterAssignmentRole::Main);
    $replacementMain = $finalAssignments->first(fn (RosterAssignment $assignment): bool => $assignment->role === RosterAssignmentRole::Main
        && $assignment->roster_shift_id !== $mainAbsent->roster_shift_id);
    $replacementOptional = $finalAssignments->first(fn (RosterAssignment $assignment): bool => $assignment->role === RosterAssignmentRole::Optional
        && $assignment->roster_shift_id === $replacementMain->roster_shift_id);
    $optionalWorked = $finalAssignments->first(fn (RosterAssignment $assignment): bool => $assignment->role === RosterAssignmentRole::Optional
        && $assignment->roster_shift_id !== $replacementMain->roster_shift_id);
    $this->put(workflowUrl('rosters.actual-work.save', 2026, 10, ['assignment' => $mainAbsent->id]), ['exception_type' => 'main_absent'])->assertRedirect();
    $this->put(workflowUrl('rosters.actual-work.save', 2026, 10, ['assignment' => $replacementMain->id]), [
        'exception_type' => 'replacement', 'actual_doctor_id' => $replacementOptional->doctor_id,
    ])->assertRedirect();
    $this->put(workflowUrl('rosters.actual-work.save', 2026, 10, ['assignment' => $optionalWorked->id]), ['exception_type' => 'optional_worked'])->assertRedirect();
    $this->post(workflowUrl('rosters.actual-work.confirm', 2026, 10))->assertRedirect();
    expect($october->fresh()->actual_work_confirmed_at)->not->toBeNull();
    expect(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))
        ->orderBy('id')->pluck('doctor_id', 'id')->all())->toBe($planned);
    expect(app(RosterDocumentService::class)->build($october->fresh()))->toBe($document);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00'));
    $this->post(workflowUrl('rosters.store', 2026, 11))->assertRedirect();
    expect(app(RosterHistoryReadinessService::class)->forMonth(2026, 11)['ready'])->toBeTrue();
    $this->post(workflowUrl('rosters.generate', 2026, 11))->assertRedirect();
    expect(Roster::query()->where('year', 2026)->where('month', 11)->firstOrFail()->last_generated_at)->not->toBeNull();
    foreach ($doctors as $doctor) {
        $history = DoctorMonthlyWorkload::query()->where('doctor_id', $doctor->id)->where('year', 2026)->where('month', 10)->firstOrFail();
        expect($history->source)->toBe(DoctorMonthlyWorkloadSource::System)
            ->and($history->optional_assignment_count)->toBe(RosterAssignment::query()->where('doctor_id', $doctor->id)
            ->where('role', RosterAssignmentRole::Optional)
            ->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $october->id))->count());
    }
});

it('fully staffs and finalizes a normal month with exactly the fourteen seeded doctors', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $admin = User::factory()->create();
    $doctors = Doctor::query()->orderByDesc('is_active')->orderBy('short_code')->get();
    expect($doctors)->toHaveCount(15)
        ->and($doctors->where('is_active', true))->toHaveCount(14)
        ->and($doctors->where('is_active', false)->pluck('short_code')->all())->toBe(['H']);
    $this->actingAs($admin)->post(workflowUrl('initial-workload.save', 2026, 9), workflowBaseline($doctors))->assertRedirect();

    $this->post(workflowUrl('rosters.store', 2026, 10))->assertRedirect();
    $roster = Roster::query()->where('year', 2026)->where('month', 10)->firstOrFail();
    $this->post(workflowUrl('rosters.generate', 2026, 10))->assertRedirect();

    $shifts = $roster->shifts()->with(['shiftType', 'assignments'])->get();
    $requiredMain = $shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->main_count);
    $requiredOptional = $shifts->sum(fn (RosterShift $shift): int => $shift->shiftType->optional_count);
    $assignments = $shifts->flatMap->assignments;
    $inactiveDoctor = $doctors->firstWhere('is_active', false);
    expect($assignments->where('role', RosterAssignmentRole::Main))->toHaveCount($requiredMain)
        ->and($assignments->where('role', RosterAssignmentRole::Optional))->toHaveCount($requiredOptional)
        ->and($assignments->contains('doctor_id', $inactiveDoctor->id))->toBeFalse();
    foreach ($shifts as $shift) {
        foreach ([RosterAssignmentRole::Main, RosterAssignmentRole::Optional] as $role) {
            $capacity = $role === RosterAssignmentRole::Main ? $shift->shiftType->main_count : $shift->shiftType->optional_count;
            expect($shift->assignments->where('role', $role)->pluck('slot_number')->sort()->values()->all())
                ->toBe($capacity === 0 ? [] : range(1, $capacity));
        }
        expect($shift->assignments->pluck('doctor_id')->unique())->toHaveCount($shift->assignments->count());
    }
    $eligibility = app(DoctorAssignmentEligibilityService::class);
    foreach ($assignments->groupBy('doctor_id') as $doctorAssignments) {
        foreach ($doctorAssignments as $first) {
            foreach ($doctorAssignments as $second) {
                if ($first->id !== $second->id) {
                    expect($eligibility->shiftConflict($first->rosterShift, $second->rosterShift))->toBeNull();
                }
            }
        }
    }
    expect(collect(app(RosterDraftValidationService::class)->validate($roster->fresh()))
        ->where('severity', 'Error'))->toBeEmpty();

    $finalize = $this->postJson(workflowUrl('rosters.finalize', 2026, 10))->assertOk();
    if ($finalize->json('status') === 'confirmation_required') {
        $this->postJson(workflowUrl('rosters.finalize', 2026, 10), [
            'warning_signature' => $finalize->json('warning_signature'),
        ])->assertJsonPath('status', 'finalized');
    } else {
        expect($finalize->json('status'))->toBe('finalized');
    }
    expect($roster->fresh()->status)->toBe(RosterStatus::Final);
});

it('creates the right shift structure in common, leap, thirty-day, and thirty-one-day months', function (int $year, int $month, int $days) {
    $this->seed(ShiftTypesSeeder::class);
    $admin = User::factory()->create();
    $roster = app(RosterStructureService::class)->create($year, $month, $admin);
    expect($roster->shifts()->distinct()->count('shift_date'))->toBe($days);
    $start = CarbonImmutable::create($year, $month, 1);
    for ($day = 1; $day <= $days; $day++) {
        $date = $start->addDays($day - 1);
        $codes = $roster->shifts()->whereDate('shift_date', $date->toDateString())->with('shiftType')->get()
            ->pluck('shiftType.code')->sort()->values()->all();
        expect($codes)->toBe($date->isWeekend()
            ? ['weekend_day', 'weekend_night']
            : ['weekday_day', 'weekday_evening', 'weekday_night']);
    }
    app(RosterStructureService::class)->create($year, $month, $admin);
    expect(Roster::query()->where('year', $year)->where('month', $month)->count())->toBe(1);
})->with([
    'common February' => [2027, 2, 28],
    'leap February' => [2028, 2, 29],
    'thirty days' => [2026, 4, 30],
    'thirty-one days' => [2026, 10, 31],
]);

it('uses December baseline history to generate January across the year boundary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-15 09:00:00'));
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $doctors = Doctor::query()->orderByDesc('is_active')->orderBy('short_code')->get();
    $this->actingAs(User::factory()->create())
        ->post(workflowUrl('initial-workload.save', 2026, 12), workflowBaseline($doctors))->assertRedirect();
    $readiness = app(RosterHistoryReadinessService::class)->forMonth(2027, 1);
    expect($readiness['ready'])->toBeTrue()->and($readiness['year'])->toBe(2026)->and($readiness['month'])->toBe(12);
    $this->post(workflowUrl('rosters.store', 2027, 1))->assertRedirect();
    $this->post(workflowUrl('rosters.generate', 2027, 1))->assertRedirect();
    expect(Roster::query()->where('year', 2027)->where('month', 1)->firstOrFail()->last_generated_at)->not->toBeNull();
});

it('carries a confirmed historical correction forward without changing a later draft plan', function () {
    $this->seed(ShiftTypesSeeder::class);
    $admin = User::factory()->create();
    $doctors = collect(['A', 'B', 'C'])->map(fn (string $code): Doctor => Doctor::create([
        'name' => "Doctor $code", 'short_code' => $code, 'is_active' => true,
    ]));
    $dayType = ShiftType::query()->where('code', 'weekday_day')->firstOrFail();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
    $this->actingAs($admin)->post(workflowUrl('initial-workload.save', 2026, 9), workflowBaseline($doctors))->assertRedirect();

    $october = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    snapshotRosterParticipation($october, $doctors);
    $octoberShift = RosterShift::create(['roster_id' => $october->id, 'shift_date' => '2026-10-01', 'shift_type_id' => $dayType->id]);
    $octoberAssignment = RosterAssignment::create([
        'roster_shift_id' => $octoberShift->id, 'doctor_id' => $doctors[0]->id,
        'role' => RosterAssignmentRole::Main, 'slot_number' => 1,
    ]);
    $this->post(workflowUrl('rosters.actual-work.confirm', 2026, 10))->assertRedirect();
    $november = Roster::create(['year' => 2026, 'month' => 11, 'status' => RosterStatus::Final, 'created_by' => $admin->id]);
    snapshotRosterParticipation($november, $doctors);
    $novemberShift = RosterShift::create(['roster_id' => $november->id, 'shift_date' => '2026-11-02', 'shift_type_id' => $dayType->id]);
    $novemberAssignment = RosterAssignment::create([
        'roster_shift_id' => $novemberShift->id, 'doctor_id' => $doctors[1]->id,
        'role' => RosterAssignmentRole::Main, 'slot_number' => 1,
    ]);
    $this->post(workflowUrl('rosters.actual-work.confirm', 2026, 11))->assertRedirect();
    $novemberHistory = DoctorMonthlyWorkload::query()->where('doctor_id', $doctors[0]->id)
        ->where('year', 2026)->where('month', 11)->firstOrFail();
    $beforeOpening = $novemberHistory->opening_balance_minutes;

    $december = app(RosterStructureService::class)->create(2026, 12, $admin);
    $firstShift = $december->shifts()->orderBy('id')->firstOrFail();
    $december->shifts()->where('id', '!=', $firstShift->id)->delete();
    $this->post(workflowUrl('rosters.generate', 2026, 12))->assertRedirect();
    $plan = RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $december->id))
        ->orderBy('id')->pluck('doctor_id', 'id')->all();

    $this->travel(2)->seconds();
    $this->put(workflowUrl('rosters.actual-work.save', 2026, 10, ['assignment' => $octoberAssignment->id]), [
        'exception_type' => 'main_absent',
    ])->assertRedirect();
    expect($novemberHistory->fresh()->opening_balance_minutes)->not->toBe($beforeOpening)
        ->and($novemberAssignment->fresh()->doctor_id)->toBe($doctors[1]->id);
    expect(RosterAssignment::query()->whereHas('rosterShift', fn ($query) => $query->where('roster_id', $december->id))
        ->orderBy('id')->pluck('doctor_id', 'id')->all())->toBe($plan);
    expect(collect(app(RosterDraftValidationService::class)->validate($december->fresh()))
        ->contains(fn (array $issue): bool => $issue['severity'] === 'Warning' && str_contains($issue['message'], 'history changed')))->toBeTrue();

    $this->travel(2)->seconds();
    $this->post(workflowUrl('rosters.regenerate', 2026, 12))->assertRedirect();
    expect(collect(app(RosterDraftValidationService::class)->validate($december->fresh()))
        ->contains(fn (array $issue): bool => str_contains($issue['message'], 'history changed')))->toBeFalse();
});
