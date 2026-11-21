@php($photo = $getRecord())
@if ($url = $photo->temporaryUrl())
    <img class="m-2 h-16 w-16 rounded-lg object-cover" src="{{ $url }}" alt="" loading="lazy" referrerpolicy="no-referrer">
@else
    <span class="px-3 text-sm text-gray-500">{{ $photo->archived_at ? 'Foto archivada' : 'Sin vista previa' }}</span>
@endif
