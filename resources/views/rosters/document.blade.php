<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Doctor Duty Roster - {{ $month }}</title>
    <style>
        @page { size: A4 landscape; margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; margin: 0; }
        .toolbar { margin: 0 0 16px; }
        .toolbar button { padding: 8px 16px; cursor: pointer; }
        h1 { font-size: 20px; margin: 0 0 3px; }
        .subtitle { margin: 0 0 13px; font-size: 13px; }
        .status { display: inline-block; margin-left: 12px; padding: 4px 9px; border: 2px solid #172033; font-weight: bold; letter-spacing: 1px; }
        .draft { border: 3px solid #8b1c1c; color: #8b1c1c; padding: 6px 10px; font-size: 18px; }
        .legend { margin: 0 0 10px; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #8c96a4; padding: 6px; vertical-align: top; text-align: left; }
        th { background: #e8edf1; font-size: 11px; }
        td div { margin-bottom: 3px; }
        .date { width: 13%; }
        .shift { width: 21%; }
        .duty { width: 33%; }
        .unfilled { font-weight: bold; color: #8b1c1c; }
        @media screen { body { max-width: 1050px; margin: 24px auto; padding: 0 16px; } .table-wrap { overflow-x: auto; } table { min-width: 850px; } }
        @media print { .toolbar { display: none; } body { margin: 0; padding: 0; } }
    </style>
</head>
<body>
    @if ($print_button)
        <div class="toolbar"><button type="button" onclick="window.print()">Print</button></div>
    @endif
    <h1>Doctor Duty Roster <span class="status {{ $is_draft ? 'draft' : '' }}">{{ $is_draft ? 'DRAFT' : 'Final' }}</span></h1>
    <p class="subtitle">{{ $month }}</p>
    <p class="legend"><strong>Main</strong> - scheduled primary duty &nbsp; | &nbsp; <strong>Optional</strong> - optional/backup duty</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th class="date">Date</th><th class="shift">Shift / time</th><th class="duty">Main</th><th class="duty">Optional</th></tr></thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td><strong>{{ $row['shift'] }}</strong><br>{{ $row['time'] }}</td>
                    <td>@foreach ($row['main'] as $slot)<div class="{{ $slot === 'UNFILLED' ? 'unfilled' : '' }}">{{ $loop->iteration }}. {{ $slot }}</div>@endforeach</td>
                    <td>@foreach ($row['optional'] as $slot)<div class="{{ $slot === 'UNFILLED' ? 'unfilled' : '' }}">{{ $loop->iteration }}. {{ $slot }}</div>@endforeach</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
