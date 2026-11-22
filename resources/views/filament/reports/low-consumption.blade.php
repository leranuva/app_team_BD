<div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-200">
            <tr>
                @foreach (['Material', 'Unidad', 'Obra', 'Scope', 'Entregado', 'Consumido', '% Consumo', 'Última actualización'] as $heading)
                    <th class="px-3 py-2 font-medium">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    @foreach ($row as $cell)
                        <td class="px-3 py-2">{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="px-3 py-4" colspan="8">No hay materiales bajo el umbral de consumo.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
