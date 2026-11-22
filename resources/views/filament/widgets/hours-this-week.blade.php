<x-filament-widgets::widget>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="text-base font-semibold">Horas de esta semana</h2>
        <table class="mt-3 w-full text-left text-sm">
            <thead>
                <tr>
                    <th class="py-1">Obra</th>
                    <th class="py-1">Regulares</th>
                    <th class="py-1">Extra</th>
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
                    <tr><td class="py-1" colspan="3">Sin horas cerradas esta semana.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-widgets::widget>
