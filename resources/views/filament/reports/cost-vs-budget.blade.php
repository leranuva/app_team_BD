<div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-200">
            <tr>
                @foreach (['Job site', 'Client', 'Budget', 'Actual cost', 'Difference', '% of budget used'] as $heading)
                    <th class="px-3 py-2 font-medium">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['sites'] as $site)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">{{ $site['name'] }}</td>
                    <td class="px-3 py-2">{{ $site['client'] }}</td>
                    <td class="px-3 py-2">{{ $site['budget'] ?? 'No budget' }}</td>
                    <td class="px-3 py-2">{{ $site['real_cost'] }}</td>
                    <td class="px-3 py-2">{{ $site['difference'] }}</td>
                    <td class="px-3 py-2">{{ $site['percent_executed'] ?? 'No budget' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-4" colspan="6">No job sites available.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if (($report['unpriced_items'] ?? 0) > 0)
        <p class="px-3 py-2 text-sm text-gray-600">{{ trans_choice(':count item has no recorded price.|:count items have no recorded price.', $report['unpriced_items']) }}</p>
    @endif
</div>
