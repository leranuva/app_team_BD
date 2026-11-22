<div class="space-y-4">
    @if ($url = $photo->temporaryUrl())
        <img class="mx-auto max-h-96 w-full rounded-xl object-contain" src="{{ $url }}" alt="{{ $photo->caption ?: 'Progress photo' }}" referrerpolicy="no-referrer">
    @else
        <p>{{ $photo->archived_at ? 'Photo archived' : 'Preview unavailable' }}</p>
    @endif
    <dl class="space-y-2 text-sm">
        <dt class="font-semibold">Description</dt>
        <dd>{{ $photo->caption ?: 'No description' }}</dd>
        <dt class="font-semibold">Taken</dt>
        <dd>{{ $photo->taken_at?->setTimezone(config('app.business_timezone'))->format('m/d/Y H:i') ?? 'No date' }}</dd>
        <dt class="font-semibold">Uploaded by</dt>
        <dd>{{ $photo->uploadedBy?->name ?? 'Unknown user' }}</dd>
    </dl>
    @if ($photo->lat !== null && $photo->lng !== null)
        <a class="underline" href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($photo->lat.','.$photo->lng) }}" target="_blank" rel="noopener noreferrer">View location in Google Maps</a>
    @endif
</div>
