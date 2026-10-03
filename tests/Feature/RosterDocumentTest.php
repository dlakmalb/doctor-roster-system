<?php

use App\Enums\ActualWorkExceptionType;
use App\Enums\RosterAssignmentRole;
use App\Enums\RosterStatus;
use App\Models\ActualWorkException;
use App\Models\Doctor;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RosterDocumentService;
use Carbon\CarbonImmutable;

function documentFixture(): array
{
    $admin = User::factory()->create();
    $planned = Doctor::create(['name' => 'Doctor Planned', 'short_code' => 'P', 'is_active' => true]);
    $replacement = Doctor::create(['name' => 'Doctor Replacement', 'short_code' => 'R', 'is_active' => true]);
    $type = ShiftType::create(['code' => 'doc_night', 'name' => 'Night', 'start_time' => '20:00:00', 'end_time' => '08:00:00', 'duration_minutes' => 720, 'main_count' => 1, 'optional_count' => 1, 'is_overnight' => true, 'is_active' => true]);
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-31', 'shift_type_id' => $type->id]);
    $assignment = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $planned->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    return [$admin, $planned, $replacement, $type, $roster, $shift, $assignment];
}

function documentUrl(string $action): string
{
    return route("rosters.$action", ['year' => 2026, 'month' => 10]);
}

it('renders a Draft print preview with planned doctors, unfilled slots, legend, and next-day time', function () {
    [$admin] = documentFixture();

    $this->actingAs($admin)->get(documentUrl('print'))
        ->assertOk()->assertSee('October 2026')->assertSee('DRAFT')
        ->assertSee('Doctor Planned')->assertSee('UNFILLED')
        ->assertSee('Main')->assertSee('Optional')->assertSee('(+1 day)')
        ->assertSee('window.print()');
});

it('renders the Final planned roster despite an actual-work replacement', function () {
    [$admin, $planned, $replacement, , $roster, $shift, $assignment] = documentFixture();
    $optional = Doctor::create(['name' => 'Doctor Optional', 'short_code' => 'O', 'is_active' => true]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optional->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $roster->update(['status' => RosterStatus::Final]);
    ActualWorkException::create(['roster_shift_id' => $shift->id, 'planned_assignment_id' => $assignment->id, 'exception_type' => ActualWorkExceptionType::Replacement, 'actual_doctor_id' => $replacement->id, 'recorded_by' => $admin->id]);

    $this->actingAs($admin)->get(documentUrl('print'))
        ->assertOk()->assertSee('Final')->assertDontSee('DRAFT')
        ->assertSee('Doctor Planned')->assertDontSee('Doctor Replacement');
    $data = app(RosterDocumentService::class)->build($roster->fresh());
    expect($data['rows'][0]['main'])->toBe(['P — Doctor Planned']);
    $this->get(documentUrl('pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($assignment->fresh()->doctor_id)->toBe($planned->id);
});

it('downloads valid Draft and Final PDFs without modifying roster or assignment data', function () {
    [$admin, , , , $roster, , $assignment] = documentFixture();
    $this->actingAs($admin);
    $updatedAt = $roster->fresh()->updated_at;
    $assignmentBefore = $assignment->fresh()->toArray();

    $draft = $this->get(documentUrl('pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($draft->headers->get('Content-Disposition'))->toContain('attachment', 'doctor-roster-2026-10-draft.pdf');
    expect(substr($draft->getContent(), 0, 4))->toBe('%PDF');
    expect($roster->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    $roster->update(['status' => RosterStatus::Final]);
    $updatedAt = $roster->fresh()->updated_at;
    $final = $this->get(documentUrl('pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($final->headers->get('Content-Disposition'))->toContain('doctor-roster-2026-10.pdf')->not->toContain('-draft.pdf');
    expect(substr($final->getContent(), 0, 4))->toBe('%PDF');
    expect($assignment->fresh()->toArray())->toBe($assignmentBefore);
    expect($roster->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    $this->assertDatabaseCount('rosters', 1);
    $this->assertDatabaseCount('roster_assignments', 1);
    $this->assertDatabaseCount('actual_work_exceptions', 0);
    $this->assertDatabaseCount('doctor_monthly_workloads', 0);
});

it('orders persisted shifts by date then start time regardless of insertion order', function () {
    [, $planned, , $night, $roster] = documentFixture();
    $early = ShiftType::create(['code' => 'doc_day', 'name' => 'Day', 'start_time' => '08:00:00', 'end_time' => '14:00:00', 'duration_minutes' => 360, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => false, 'is_active' => true]);
    RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-01', 'shift_type_id' => $night->id]);
    $day = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-01', 'shift_type_id' => $early->id]);
    RosterAssignment::create(['roster_shift_id' => $day->id, 'doctor_id' => $planned->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    $rows = app(RosterDocumentService::class)->build($roster)['rows'];

    expect(array_column($rows, 'shift'))->toBe(['Day', 'Night', 'Night']);
    expect(array_column($rows, 'date'))->toBe(['Thu, Oct 1', 'Thu, Oct 1', 'Sat, Oct 31']);
});

it('orders Main and Optional slots by slot number rather than insertion order', function () {
    [, $first, $second, $type, $roster, $shift] = documentFixture();
    $type->update(['main_count' => 2, 'optional_count' => 2]);
    $optionalFirst = Doctor::create(['name' => 'Doctor Optional One', 'short_code' => 'O1', 'is_active' => true]);
    $optionalSecond = Doctor::create(['name' => 'Doctor Optional Two', 'short_code' => 'O2', 'is_active' => true]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optionalSecond->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 2]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optionalFirst->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $second->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 2]);

    $row = app(RosterDocumentService::class)->build($roster)['rows'][0];

    expect($row['main'])->toBe(['P — Doctor Planned', 'R — Doctor Replacement']);
    expect($row['optional'])->toBe(['O1 — Doctor Optional One', 'O2 — Doctor Optional Two']);
});

it('renders a 31-day monthly document across PDF pages', function () {
    [$admin, , , $type, $roster] = documentFixture();
    for ($day = 1; $day <= 30; $day++) {
        RosterShift::create(['roster_id' => $roster->id, 'shift_date' => CarbonImmutable::create(2026, 10, $day)->toDateString(), 'shift_type_id' => $type->id]);
    }

    expect(app(RosterDocumentService::class)->build($roster)['rows'])->toHaveCount(31);
    $pdf = $this->actingAs($admin)->get(documentUrl('pdf'))->assertOk();
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});
