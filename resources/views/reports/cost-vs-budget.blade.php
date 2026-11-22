<!DOCTYPE html>
<html lang="es">
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
    <p>Costo real vs. presupuestado</p>
    <p>Generado {{ $generated_at->format('Y-m-d H:i') }} ({{ config('app.business_timezone') }}). Rango: {{ $range_label }}.</p>
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Cliente</th>
                <th>Presupuesto</th>
                <th>Costo real</th>
                <th>Diferencia</th>
                <th>% Ejecutado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sites as $site)
                <tr>
                    <td>{{ $site['name'] }}</td>
                    <td>{{ $site['client'] }}</td>
                    <td>{{ $site['budget'] ?? 'sin presupuesto' }}</td>
                    <td>{{ $site['real_cost'] }}</td>
                    <td>{{ $site['difference'] }}</td>
                    <td>{{ $site['percent_executed'] ?? 'sin presupuesto' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Totales</td>
                <td>{{ $totals['budget'] }}</td>
                <td>{{ $totals['real_cost'] }}</td>
                <td>{{ $totals['difference'] }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    @if ($unpriced_items > 0)
        <p>{{ $unpriced_items }} ítems sin costo registrado. No suman al costo real.</p>
    @endif
    <p>Los sitios sin presupuesto base no tienen porcentaje ejecutado.</p>
</body>
</html>
