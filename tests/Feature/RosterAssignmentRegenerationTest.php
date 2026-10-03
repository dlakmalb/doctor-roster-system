<?php

use App\Enums\DoctorRequestType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\Doctor;
use App\Models\DoctorMonthlyExclusion;
use App\Models\DoctorMonthlyWorkload;
use App\Models\DoctorRequest;
use App\Models\RosterAssignment;
use App\Models\User;
use App\Services\DoctorAssignmentEligibilityService;
use App\Services\RosterAssignmentGenerator;
use App\Services\RosterStructureService;
use Database\Seeders\DoctorsSeeder;
use Database\Seeders\ShiftTypesSeeder;

function regenerationRoster(): array
{
    test()->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $admin = User::factory()->create();

    return [$admin, app(RosterStructureService::class)->create(2026, 10, $admin)];
}

function regenerationUrl(): string
{
    return route('rosters.regenerate', ['year' => 2026, 'month' => 10]);
}

it('requires authentication, an existing roster, and Draft status', function () {
    $this->post(regenerationUrl())->assertRedirect(route('login'));
    $admin = User::factory()->create();
    $this->actingAs($admin)->post(regenerationUrl())->assertNotFound();
    $this->seed([DoctorsSeeder::class, ShiftTypesSeeder::class]);
    $roster = app(RosterStructureService::class)->create(2026, 10, $admin);
    $roster->update(['status' => RosterStatus::Final]);

    $this->from(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->post(regenerationUrl())->assertSessionHasErrors('roster');
    expect($roster->fresh()->last_generated_at)->toBeNull();
    $this->assertDatabaseCount('roster_assignments', 0);
});

it('replaces assignment rows atomically and preserves setup and workload history on repeat', function () {
    [$admin, $roster] = regenerationRoster();
    $otherAdmin = User::factory()->create();
    $doctor = Doctor::query()->orderBy('id')->firstOrFail();
    $shift = $roster->shifts()->orderBy('shift_date')->firstOrFail();
    $request = DoctorRequest::create([
        'doctor_id' => $doctor->id,
        'request_type' => DoctorRequestType::PreferredWork,
        'request_date' => $shift->shift_date,
        'shift_type_id' => $shift->shift_type_id,
    ]);
    $exclusion = DoctorMonthlyExclusion::create(['doctor_id' => $doctor->id, 'year' => 2026, 'month' => 10]);
    $history = DoctorMonthlyWorkload::create([
        'doctor_id' => $doctor->id,
        'year' => 2026,
        'month' => 9,
        'actual_worked_minutes' => 0,
        'closing_balance_minutes' => 100,
    ]);
    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    $originalIds = RosterAssignment::query()->pluck('id')->all();
    $originalGeneratedAt = $roster->fresh()->last_generated_at;
    expect($originalIds)->not->toBeEmpty();
    $this->travel(1)->minute();

    $this->actingAs($otherAdmin)->post(regenerationUrl())->assertRedirect();
    $firstReplacementIds = RosterAssignment::query()->pluck('id')->all();
    expect($firstReplacementIds)->not->toBeEmpty()
        ->and(array_intersect($originalIds, $firstReplacementIds))->toBe([]);
    expect($roster->fresh()->last_generated_at->greaterThan($originalGeneratedAt))->toBeTrue();
    $this->travel(1)->minute();
    $this->post(regenerationUrl())->assertRedirect();

    $assignments = RosterAssignment::query()->with('rosterShift.shiftType')->get();
    expect(array_intersect($firstReplacementIds, $assignments->modelKeys()))->toBe([])
        ->and($roster->fresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->fresh()->updated_by)->toBe($otherAdmin->id)
        ->and($roster->fresh()->last_generated_at)->not->toBeNull();
    $this->assertModelExists($request);
    $this->assertModelExists($exclusion);
    $this->assertModelExists($history);
    expect($history->fresh()->closing_balance_minutes)->toBe(100);
    $eligibility = app(DoctorAssignmentEligibilityService::class);
    foreach ($assignments->groupBy('roster_shift_id') as $shiftAssignments) {
        $shiftType = $shiftAssignments->firstOrFail()->rosterShift->shiftType;
        expect($shiftAssignments->where('role', RosterAssignmentRole::Main)->count())->toBeLessThanOrEqual($shiftType->main_count)
            ->and($shiftAssignments->where('role', RosterAssignmentRole::Optional)->count())->toBeLessThanOrEqual($shiftType->optional_count)
            ->and($shiftAssignments->pluck('doctor_id')->unique()->count())->toBe($shiftAssignments->count());
    }
    foreach ($assignments->groupBy('doctor_id') as $doctorAssignments) {
        foreach ($doctorAssignments as $assignment) {
            foreach ($doctorAssignments as $other) {
                if ($assignment->id !== $other->id) {
                    expect($eligibility->shiftConflict($assignment->rosterShift, $other->rosterShift))->toBeNull();
                }
            }
        }
    }
});

it('rolls back the old assignments when new persistence fails', function () {
    [$admin, $roster] = regenerationRoster();
    $this->actingAs($admin)->post(route('rosters.generate', ['year' => 2026, 'month' => 10]))->assertRedirect();
    $oldIds = RosterAssignment::query()->orderBy('id')->pluck('id')->all();
    $oldGeneratedAt = $roster->fresh()->last_generated_at?->toDateTimeString();
    RosterAssignment::creating(function (): void {
        throw new RuntimeException('Simulated assignment persistence failure');
    });

    try {
        app(RosterAssignmentGenerator::class)->regenerate($roster, $admin);
        test()->fail('Regeneration should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated assignment persistence failure');
    } finally {
        RosterAssignment::flushEventListeners();
    }

    expect(RosterAssignment::query()->orderBy('id')->pluck('id')->all())->toBe($oldIds)
        ->and($roster->fresh()->last_generated_at?->toDateTimeString())->toBe($oldGeneratedAt);
});
