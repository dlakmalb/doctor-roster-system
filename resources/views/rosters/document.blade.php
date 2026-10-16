<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Doctor Duty Roster - {{ $month }}</title>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 10pt; margin: 0; line-height: 1.2; }
        .toolbar { margin-bottom: 12pt; }
        .toolbar button { padding: 6pt 12pt; cursor: pointer; }
        h1 { text-align: center; color: #234e70; font-size: 14pt; margin: 0 0 5pt; }
        .status { text-align: center; font-weight: bold; margin: 0 0 5pt; }
        .draft { color: #b00000; font-size: 12pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; border: 1pt solid #333; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 0.6pt solid #555; padding: 2pt 4pt; vertical-align: middle; }
        th { background: #dceaf4; text-align: center; font-size: 12pt; font-weight: bold; }
        .roster-grid tbody td { font-size: 12pt; font-weight: bold; }
        /* Preserve the calendar columns' share of the 190mm A4 print area, excluding cell padding and borders. */
        .day { width: 44.96pt; }
        .date { width: 50.53pt; }
        .document-tables { float: left; width: auto; position: relative; left: 50%; transform: translateX(-50%); }
        .roster-grid { width: auto; table-layout: auto; }
        .roster-grid th, .roster-grid td { white-space: nowrap; }
        .calendar { text-align: center; }
        .weekend td { background: #eef4f8; }
        .main { color: #000; font-weight: bold; }
        .optional { color: #c00000; }
        .slot { display: inline-block; min-width: 12pt; white-space: nowrap; margin-right: 10pt; }
        .weekend-time { display: block; font-size: 7pt; margin-bottom: 1pt; }
        h2 { font-size: 12pt; margin: 6pt 0 3pt; }
        /* Fill the roster's width without letting legend text widen the shared container. */
        .reference { width: 0; min-width: 100%; font-size: 10pt; }
        .reference td { width: 33.333%; vertical-align: top; padding: 2pt 4pt; overflow-wrap: break-word; }
        @media screen { body { max-width: 190mm; margin: 20px auto; padding: 0 12px; } }
        @media print { .toolbar { display: none; } body { margin: 0; padding: 0; } * { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
    </style>
</head>
<body>
    @if ($print_button)
        <div class="toolbar"><button type="button" onclick="window.print()">Print</button></div>
    @endif
    <h1>Working Duty Roster - {{ strtoupper($month) }}</h1>
    <p class="status {{ $is_draft ? 'draft' : '' }}">{{ $is_draft ? 'DRAFT' : 'Final' }}</p>
    <div class="document-tables">
    <table class="roster-grid">
        <thead>
            <tr><th class="day" scope="col">Day</th><th class="date" scope="col">Date</th><th class="duty" scope="col">8AM - 2PM</th><th class="duty" scope="col">2PM - 8PM</th><th class="duty" scope="col">8PM - 8AM</th></tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr data-date="{{ $row['date'] }}" class="{{ $row['weekend'] ? 'weekend' : 'weekday' }}">
                <td class="calendar">{{ $row['day'] }}</td>
                <td class="calendar">{{ sprintf('%02d', $row['number']) }}</td>
                @foreach ($row['cells'] as $cell)
                    <td>
                        @foreach ($cell['main'] as $slot)<span class="slot main">{{ $slot }}</span> @endforeach
                        @foreach ($cell['optional'] as $slot)<span class="slot optional">{{ $slot }}</span> @endforeach
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
    <h2>Doctor code reference</h2>
    <table class="reference">
        <tbody>
        @foreach (array_chunk($doctors, 3) as $group)
            <tr>
                @foreach ($group as $doctor)<td><strong>{{ $doctor['code'] }}</strong> - {{ $doctor['name'] }}</td>@endforeach
                @for ($empty = count($group); $empty < 3; $empty++)<td></td>@endfor
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</body>
</html>
