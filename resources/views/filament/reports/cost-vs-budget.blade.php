<div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-200">
            <tr>
                @foreach (['Obra', 'Cliente', 'Presupuesto', 'Costo real', 'Diferencia', '% Ejecutado'] as $heading)
                    <th class="px-3 py-2 font-medium">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['sites'] as $site)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">{{ $site['name'] }}</td>
                    <td class="px-3 py-2">{{ $site['client'] }}</td>
                    <td class="px-3 py-2">{{ $site['budget'] ?? 'sin presupuesto' }}</td>
                    <td class="px-3 py-2">{{ $site['real_cost'] }}</td>
                    <td class="px-3 py-2">{{ $site['difference'] }}</td>
                    <td class="px-3 py-2">{{ $site['percent_executed'] ?? 'sin presupuesto' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-4" colspan="6">No hay obras visibles.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if (($report['unpriced_items'] ?? 0) > 0)
        <p class="px-3 py-2 text-sm text-gray-600">{{ $report['unpriced_items'] }} ítems sin costo registrado.</p>
    @endif
</div>
