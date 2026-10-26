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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia;

function documentFixture(): array
{
    $admin = User::factory()->create();
    $planned = Doctor::create(['name' => 'Doctor Planned', 'short_code' => 'P', 'is_active' => true]);
    $replacement = Doctor::create(['name' => 'Doctor Replacement', 'short_code' => 'R', 'is_active' => true]);
    $type = ShiftType::create(['code' => 'weekend_night', 'name' => 'Weekend Night', 'start_time' => '16:00:00', 'end_time' => '08:00:00', 'duration_minutes' => 960, 'main_count' => 1, 'optional_count' => 1, 'is_overnight' => true, 'is_active' => true]);
    $roster = Roster::create(['year' => 2026, 'month' => 10, 'status' => RosterStatus::Draft, 'created_by' => $admin->id]);
    $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-31', 'shift_type_id' => $type->id]);
    $shift->capacitySnapshot()->create(['main_count' => 1, 'optional_count' => 1]);
    $assignment = RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $planned->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);

    return [$admin, $planned, $replacement, $type, $roster, $shift, $assignment];
}

function documentUrl(string $action): string
{
    return route("rosters.$action", ['year' => 2026, 'month' => 10]);
}

it('renders a compact Draft print preview with time headers, planned doctors and unfilled slots', function () {
    [$admin] = documentFixture();

    $response = $this->actingAs($admin)->get(documentUrl('print'))
        ->assertOk()->assertSee('October 2026')->assertSee('DRAFT')
        ->assertSee('Doctor Planned')->assertSee('<span class="slot optional">-</span>', false)
        ->assertSee('Doctor code reference')
        ->assertDontSee('Main: black')->assertDontSee('Optional: red')->assertDontSee('unfilled position')
        ->assertDontSee('class="schedules"', false)->assertDontSee('(+1 day)')
        ->assertDontSee('Weekend Day continues')->assertDontSee('Weekend Night:')
        ->assertSee('window.print()');

    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $headers = (new DOMXPath($dom))->query('//table[@class="roster-grid"]/thead/tr/th');

    expect(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($headers)))
        ->toBe(['Day', 'Date', '8AM - 2PM', '2PM - 8PM', '8PM - 8AM']);
});

it('shows assignments and validation errors while blocking documents for a missing capacity snapshot', function () {
    [$admin, , , , , $shift] = documentFixture();
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $shift->id)->delete();

    $this->actingAs($admin)->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.main_positions', null)
            ->where('days.0.date', '2026-10-31')
            ->where('days.0.shifts.0.main.0.doctor.short_code', 'P')
            ->where('conflicts', fn (Collection $issues): bool => $issues->contains(fn (array $issue): bool => $issue['code'] === 'invalid_capacity_snapshot')));

    $this->get(documentUrl('print'))->assertStatus(409);
    $this->get(documentUrl('pdf'))->assertStatus(409);
});

it('keeps out-of-capacity assignments visible in the roster UI and blocks misleading documents', function () {
    [$admin, , , , , $shift] = documentFixture();
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $shift->id)->update(['main_count' => 0]);

    $this->actingAs($admin)->get(route('rosters.show', ['year' => 2026, 'month' => 10]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.shifts.0.main.0.slot_number', 1)
            ->where('days.0.shifts.0.main.0.doctor.short_code', 'P')
            ->where('days.0.shifts.0.main.0.severity', 'Error')
            ->where('conflicts', fn (Collection $issues): bool => $issues->contains(fn (array $issue): bool => $issue['code'] === 'assignment_outside_capacity')));

    $this->get(documentUrl('print'))->assertStatus(409);
    $this->get(documentUrl('pdf'))->assertStatus(409);
});

it('renders the Final planned roster despite an actual-work replacement', function () {
    [$admin, $planned, $replacement, , $roster, $shift, $assignment] = documentFixture();
    $optional = Doctor::create(['name' => 'Doctor Optional', 'short_code' => 'O', 'is_active' => true]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optional->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $roster->update(['status' => RosterStatus::Final]);
    ActualWorkException::create(['roster_shift_id' => $shift->id, 'planned_assignment_id' => $assignment->id, 'exception_type' => ActualWorkExceptionType::Replacement, 'actual_doctor_id' => $replacement->id, 'recorded_by' => $admin->id]);

    $this->actingAs($admin)->get(documentUrl('print'))
        ->assertOk()->assertDontSee('Final')->assertDontSee('DRAFT')
        ->assertDontSee('<p class="status', false)
        ->assertSee('Doctor Planned')->assertDontSee('Doctor Replacement');
    $data = app(RosterDocumentService::class)->build($roster->fresh());
    expect($data['rows'][30]['cells'][2]['main'])->toBe(['P']);
    $this->get(documentUrl('pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($assignment->fresh()->doctor_id)->toBe($planned->id);
});

it('orders the Print and PDF reference by code with eligible N first', function (array $assignedCodes, array $expectedCodes) {
    [$admin, , , $type, , $shift] = documentFixture();
    $type->update(['optional_count' => count($assignedCodes)]);
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $shift->id)->update(['optional_count' => count($assignedCodes)]);
    $doctors = collect([
        'U' => 'Dr Aaron',
        'B' => 'Dr Zed',
        'N' => 'Dr Nuwan (MOIC)',
        'A' => 'Dr Zoe',
        'C' => 'Dr Adam',
    ])->map(fn (string $name, string $code): Doctor => Doctor::create([
        'name' => $name, 'short_code' => $code, 'is_active' => true,
    ]));
    foreach ($assignedCodes as $index => $code) {
        RosterAssignment::create([
            'roster_shift_id' => $shift->id, 'doctor_id' => $doctors[$code]->id,
            'role' => RosterAssignmentRole::Optional, 'slot_number' => $index + 1,
        ]);
    }
    $expectedNames = ['P' => 'Doctor Planned', ...$doctors->map->name->all()];
    $expectedEntries = array_map(fn (string $code): string => $code.' - '.$expectedNames[$code], $expectedCodes);

    $preview = $this->actingAs($admin)->get(documentUrl('print'))->assertOk();
    $printDom = new DOMDocument;
    @$printDom->loadHTML($preview->getContent());
    $printEntries = (new DOMXPath($printDom))->query('//table[@class="reference"]/tbody/tr/td[strong]');

    expect(array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($printEntries)))
        ->toBe($expectedEntries);

    $pdfReference = [];
    View::composer('rosters.document', function (Illuminate\View\View $view) use (&$pdfReference): void {
        $pdfReference = $view->getData()['doctors'];
    });

    $this->get(documentUrl('pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(array_map(fn (array $doctor): string => $doctor['code'].' - '.$doctor['name'], $pdfReference))
        ->toBe($expectedEntries);
})->with([
    'N assigned' => [['U', 'B', 'N', 'A', 'C'], ['N', 'A', 'B', 'C', 'P', 'U']],
    'N not assigned' => [['U', 'B', 'A', 'C'], ['A', 'B', 'C', 'P', 'U']],
]);

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

it('places weekday shifts in Day, Evening and Night columns regardless of insertion order', function () {
    [$admin, , , $type, $roster] = documentFixture();
    foreach ([
        'weekday_night' => ['N', '20:00:00', '08:00:00'],
        'weekday_evening' => ['E', '14:00:00', '20:00:00'],
        'weekday_day' => ['D', '08:00:00', '14:00:00'],
    ] as $code => [$shortCode, $start, $end]) {
        $weekdayType = ShiftType::create([
            ...$type->only(['start_time', 'end_time', 'duration_minutes', 'is_overnight', 'is_active']),
            'code' => $code, 'name' => $code, 'main_count' => 1, 'optional_count' => 0,
            'start_time' => $start, 'end_time' => $end,
        ]);
        $doctor = Doctor::create(['name' => $code, 'short_code' => $shortCode, 'is_active' => true]);
        $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-01', 'shift_type_id' => $weekdayType->id]);
        $shift->capacitySnapshot()->create(['main_count' => 1, 'optional_count' => 0]);
        RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    }

    $rows = app(RosterDocumentService::class)->build($roster)['rows'];

    expect($rows[0]['cells'])->toBe([
        ['main' => ['D'], 'optional' => []],
        ['main' => ['E'], 'optional' => []],
        ['main' => ['N'], 'optional' => []],
    ]);
    expect(array_column($rows, 'date'))->toHaveCount(31);
    expect($rows[30]['cells'][2]['main'])->toBe(['P']);
    $this->actingAs($admin)->get(documentUrl('print'))->assertOk()
        ->assertSeeInOrder(['<span class="slot main">D</span>', '<span class="slot main">E</span>', '<span class="slot main">N</span>'], false)
        ->assertSee('8AM - 2PM')->assertSee('2PM - 8PM')->assertSee('8PM - 8AM');
});

it('orders Main and Optional slots by slot number rather than insertion order', function () {
    [, $first, $second, $type, $roster, $shift] = documentFixture();
    $type->update(['main_count' => 2, 'optional_count' => 2]);
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $shift->id)->update(['main_count' => 2, 'optional_count' => 2]);
    $optionalFirst = Doctor::create(['name' => 'Doctor Optional One', 'short_code' => 'O1', 'is_active' => true]);
    $optionalSecond = Doctor::create(['name' => 'Doctor Optional Two', 'short_code' => 'O2', 'is_active' => true]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optionalSecond->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 2]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optionalFirst->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $second->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 2]);

    $row = app(RosterDocumentService::class)->build($roster)['rows'][30]['cells'][2];

    expect($row['main'])->toBe(['P', 'R']);
    expect($row['optional'])->toBe(['O1', 'O2']);
});

it('renders all 31 calendar dates and generates a monthly PDF', function () {
    [$admin, , , $type, $roster] = documentFixture();
    for ($day = 1; $day <= 30; $day++) {
        $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => CarbonImmutable::create(2026, 10, $day)->toDateString(), 'shift_type_id' => $type->id]);
        $shift->capacitySnapshot()->create(['main_count' => 1, 'optional_count' => 1]);
    }

    $preview = $this->actingAs($admin)->get(documentUrl('print'))->assertOk();
    expect(substr_count($preview->getContent(), 'data-date='))->toBe(31);
    $preview->assertSee('data-date="2026-10-01"', false)->assertSee('data-date="2026-10-31"', false);
    $pdf = $this->actingAs($admin)->get(documentUrl('pdf'))->assertOk();
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('continues Weekend Day Optional slots in saved order without an Evening assignment', function (string $date) {
    [$admin, $main, , , $roster, $nightShift] = documentFixture();
    $dayType = ShiftType::create([
        'code' => 'weekend_day', 'name' => 'Weekend Day', 'start_time' => '08:00:00', 'end_time' => '16:00:00',
        'duration_minutes' => 480, 'main_count' => 3, 'optional_count' => 7, 'is_overnight' => false, 'is_active' => true,
    ]);
    $shift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => $date, 'shift_type_id' => $dayType->id]);
    $shift->capacitySnapshot()->create(['main_count' => 3, 'optional_count' => 7]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $main->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 1]);
    $secondMain = Doctor::create(['name' => 'Second Main', 'short_code' => 'M2', 'is_active' => true]);
    $thirdMain = Doctor::create(['name' => 'Third Main', 'short_code' => 'M3', 'is_active' => true]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $secondMain->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 2]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $thirdMain->id, 'role' => RosterAssignmentRole::Main, 'slot_number' => 3]);
    $nightShift->update(['shift_date' => $date]);
    foreach ([7, 4, 3, 2, 1] as $slot) {
        $doctor = Doctor::create(['name' => 'Optional '.$slot, 'short_code' => 'O'.$slot, 'is_active' => true]);
        RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $doctor->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => $slot]);
    }

    $response = $this->actingAs($admin)->get(documentUrl('print'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    $cells = $xpath->query('//tr[@data-date="'.$date.'"]/td');

    expect($cells->length)->toBe(5);
    $dayCodes = $xpath->query('//tr[@data-date="'.$date.'"]/td[3]/span[contains(@class,"slot")]');
    expect(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($dayCodes)))
        ->toBe(['P', 'M2', 'M3', 'O1', 'O2', 'O3']);
    $continuationCodes = $xpath->query('//tr[@data-date="'.$date.'"]/td[4]/span[contains(@class,"slot")]');
    expect(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($continuationCodes)))
        ->toBe(['O4', '-', '-', 'O7']);
    expect($xpath->query('//tr[@data-date="'.$date.'" and @class="weekend"]')->length)->toBe(1);
    expect($xpath->query('//tr[@data-date="'.$date.'"]/td[4]/span[contains(@class,"main")]')->length)->toBe(0);
    expect($xpath->query('//tr[@data-date="'.$date.'"]/td[3]/span[@class="weekend-time"]')->length)->toBe(0);
    expect($xpath->query('//tr[@data-date="'.$date.'"]/td[3]/span[contains(@class,"slot")]')->length)->toBeGreaterThan(0);
    expect($xpath->query('//tr[@data-date="'.$date.'"]/td[5]/span[@class="weekend-time"]')->length)->toBe(0);
    expect($xpath->query('//tr[@data-date="'.$date.'"]/td[5]/span[contains(@class,"slot")]')->length)->toBeGreaterThan(0);
    expect(ShiftType::query()->where('code', 'weekend_evening')->exists())->toBeFalse();
})->with(['Saturday' => '2026-10-03', 'Sunday' => '2026-10-04']);

it('renders weekday codes and vacancies with role formatting and no zero-capacity vacancies', function () {
    [$admin, , , $type, $roster, $shift] = documentFixture();
    $type->update(['code' => 'weekday_day', 'name' => 'Weekday Day', 'main_count' => 2, 'optional_count' => 2]);
    $shift->update(['shift_date' => '2026-10-01']);
    DB::table('roster_shift_capacity_snapshots')->where('roster_shift_id', $shift->id)->update(['main_count' => 2, 'optional_count' => 2]);
    $optional = Doctor::create(['name' => 'Optional Doctor', 'short_code' => 'O', 'is_active' => true]);
    RosterAssignment::create(['roster_shift_id' => $shift->id, 'doctor_id' => $optional->id, 'role' => RosterAssignmentRole::Optional, 'slot_number' => 1]);
    $nightType = ShiftType::create([
        'code' => 'weekday_night', 'name' => 'Weekday Night', 'start_time' => '20:00:00', 'end_time' => '08:00:00',
        'duration_minutes' => 720, 'main_count' => 1, 'optional_count' => 0, 'is_overnight' => true, 'is_active' => true,
    ]);
    $nightShift = RosterShift::create(['roster_id' => $roster->id, 'shift_date' => '2026-10-01', 'shift_type_id' => $nightType->id]);
    $nightShift->capacitySnapshot()->create(['main_count' => 1, 'optional_count' => 0]);

    $response = $this->actingAs($admin)->get(documentUrl('print'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);

    foreach (['main' => ['P', '-'], 'optional' => ['O', '-']] as $role => $expected) {
        $codes = $xpath->query('//tr[@data-date="2026-10-01"]/td[3]/span[@class="slot '.$role.'"]');
        expect(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($codes)))->toBe($expected);
    }
    expect($xpath->query('//tr[@data-date="2026-10-01"]/td[5]/span[@class="slot optional"]')->length)->toBe(0);
    $response->assertSee('.main { color: #000;', false)->assertSee('.optional { color: #c00000;', false);
});

it('renders every calendar date for any month length', function (int $year, int $month, int $days) {
    [$admin, , , , $roster] = documentFixture();
    $roster->update(['year' => $year, 'month' => $month]);

    $response = $this->actingAs($admin)->get(route('rosters.print', ['year' => $year, 'month' => $month]))->assertOk();

    expect(substr_count($response->getContent(), 'data-date='))->toBe($days);
    $response->assertSee(sprintf('data-date="%04d-%02d-%02d"', $year, $month, $days), false);
    $response->assertSee(strtoupper(CarbonImmutable::create($year, $month, 1)->format('F Y')));
})->with(['28 days' => [2027, 2, 28], 'leap February' => [2028, 2, 29], '30 days' => [2026, 11, 30], '31 days' => [2026, 10, 31]]);

it('escapes database doctor codes and names in cells and reference', function () {
    [$admin, $doctor] = documentFixture();
    $doctor->update(['short_code' => '<P>', 'name' => '<script>alert("doctor")</script>']);

    $this->actingAs($admin)->get(documentUrl('print'))->assertOk()
        ->assertSee('<P>')->assertSee('<script>alert("doctor")</script>')
        ->assertDontSee('<script>alert("doctor")</script>', false);
});

it('leaves saved roster, shift, assignment and history records unchanged on preview and PDF requests', function () {
    [$admin, , , , $roster] = documentFixture();
    $tables = ['rosters', 'roster_shifts', 'roster_assignments', 'shift_types', 'doctors', 'actual_work_exceptions', 'doctor_monthly_workloads', 'doctor_monthly_participations'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    $this->actingAs($admin)->get(documentUrl('print'))->assertOk();
    $this->get(documentUrl('pdf'))->assertOk();

    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
});
