<x-filament-widgets::widget>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold">Lowest usage</h2>
            <a class="text-sm font-medium underline" href="{{ $url }}">View report</a>
        </div>
        <ul class="mt-3 space-y-1 text-sm">
            @forelse ($rows as $row)
                <li>{{ $row['material'] }} · {{ $row['site'] }} · {{ $row['percent'] }} %</li>
            @empty
                <li>No materials below the threshold.</li>
            @endforelse
        </ul>
    </div>
</x-filament-widgets::widget>
