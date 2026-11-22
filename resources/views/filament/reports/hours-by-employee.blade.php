<div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-200">
            <tr>
                <th class="px-3 py-2 font-medium">Trabajador</th>
                <th class="px-3 py-2 font-medium">Rol</th>
                <th class="px-3 py-2 font-medium">Obra</th>
                <th class="px-3 py-2 font-medium">Semana</th>
                <th class="px-3 py-2 font-medium">Horas regulares</th>
                <th class="px-3 py-2 font-medium">Horas extra</th>
                <th class="px-3 py-2 font-medium">Doble tiempo</th>
                <th class="px-3 py-2 font-medium">Total</th>
                <th class="px-3 py-2 font-medium">Costo (USD)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">{{ $row['worker'] }}</td>
                    <td class="px-3 py-2">{{ $row['role'] }}</td>
                    <td class="px-3 py-2">{{ $row['site'] }}</td>
                    <td class="px-3 py-2">{{ $row['week'] }}</td>
                    <td class="px-3 py-2">{{ $row['regular_hours'] }}</td>
                    <td class="px-3 py-2">{{ $row['overtime_hours'] }}</td>
                    <td class="px-3 py-2">{{ $row['double_time_hours'] }}</td>
                    <td class="px-3 py-2">{{ $row['total_hours'] }}</td>
                    <td class="px-3 py-2">{{ $row['cost'] }}</td>
                </tr>
            @empty
                <tr>
                    <td class="px-3 py-4 text-gray-600 dark:text-gray-300" colspan="9">No hay asistencias cerradas en este rango.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
