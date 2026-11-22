<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        h1 { font-size: 16px; }
    </style>
</head>
<body>
    <h1>Export sample</h1>
    <p>Generated {{ $generatedAt->format('Y-m-d H:i') }} ({{ config('app.business_timezone') }}).</p>
    <table>
        <thead>
            <tr>
                <th>Worker</th>
                <th>Job site</th>
                <th>Overtime</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['worker'] }}</td>
                    <td>{{ $row['site'] }}</td>
                    <td>{{ $row['overtime_hours'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
