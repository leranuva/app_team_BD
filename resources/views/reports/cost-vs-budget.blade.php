<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 20mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        tfoot td { font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ config('app.name') }}</h1>
    <p>Actual vs. budgeted cost</p>
    <p>Generated {{ $generated_at->format('Y-m-d H:i') }} ({{ config('app.business_timezone') }}). Date range: {{ $range_label }}.</p>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Client</th>
                <th>Budget</th>
                <th>Actual cost</th>
                <th>Difference</th>
                <th>% of budget used</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sites as $site)
                <tr>
                    <td>{{ $site['name'] }}</td>
                    <td>{{ $site['client'] }}</td>
                    <td>{{ $site['budget'] ?? 'No budget' }}</td>
                    <td>{{ $site['real_cost'] }}</td>
                    <td>{{ $site['difference'] }}</td>
                    <td>{{ $site['percent_executed'] ?? 'No budget' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Totals</td>
                <td>{{ $totals['budget'] }}</td>
                <td>{{ $totals['real_cost'] }}</td>
                <td>{{ $totals['difference'] }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    @if ($unpriced_items > 0)
        <p>{{ trans_choice(':count item was excluded from actual cost because it has no recorded price.|:count items were excluded from actual cost because they have no recorded price.', $unpriced_items) }}</p>
    @endif
    <p>Budget usage is unavailable for job sites without a base budget.</p>
</body>
</html>
