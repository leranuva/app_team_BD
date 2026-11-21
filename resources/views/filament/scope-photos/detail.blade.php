<div class="space-y-4">
    @if ($url = $photo->temporaryUrl())
        <img class="mx-auto max-h-96 w-full rounded-xl object-contain" src="{{ $url }}" alt="{{ $photo->caption ?: 'Foto de avance' }}" referrerpolicy="no-referrer">
    @else
        <p>{{ $photo->archived_at ? 'Foto archivada' : 'Vista previa no disponible' }}</p>
    @endif
    <dl class="space-y-2 text-sm">
        <dt class="font-semibold">Descripción</dt>
        <dd>{{ $photo->caption ?: 'Sin descripción' }}</dd>
        <dt class="font-semibold">Captura</dt>
        <dd>{{ $photo->taken_at?->setTimezone(config('app.business_timezone'))->format('m/d/Y H:i') ?? 'Sin fecha' }}</dd>
        <dt class="font-semibold">Subida por</dt>
        <dd>{{ $photo->uploadedBy?->name ?? 'Sin usuario' }}</dd>
    </dl>
    @if ($photo->lat !== null && $photo->lng !== null)
        <a class="underline" href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($photo->lat.','.$photo->lng) }}" target="_blank" rel="noopener noreferrer">Ver ubicación en Google Maps</a>
    @endif
</div>
