<x-filament-widgets::widget>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="text-base font-semibold">Hours this week</h2>
        <table class="mt-3 w-full text-left text-sm">
            <thead>
                <tr>
                    <th class="py-1">Job site</th>
                    <th class="py-1">Regular</th>
                    <th class="py-1">Overtime</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="py-1">{{ $row['site'] }}</td>
                        <td class="py-1">{{ $row['regular_hours'] }}</td>
                        <td class="py-1">{{ $row['overtime_hours'] }}</td>
                    </tr>
                @empty
                    <tr><td class="py-1" colspan="3">No completed time entries this week.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-widgets::widget>
