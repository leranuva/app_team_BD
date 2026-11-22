@php
    $latitude = (float) $lat;
    $longitude = (float) $lng;
    $meters = (int) $radius;
    $maps = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($latitude.','.$longitude);
@endphp

<div class="grid gap-3">
    <div
        id="job-site-map"
        class="h-80 w-full overflow-hidden rounded-xl"
        data-lat="{{ $latitude }}"
        data-lng="{{ $longitude }}"
        data-radius="{{ $meters }}"
        role="img"
        aria-label="Mapa de la obra"
    ></div>
    <a href="{{ $maps }}" target="_blank" rel="noopener noreferrer">Ver en mapa externo</a>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const node = document.getElementById('job-site-map');
        if (!node || typeof L === 'undefined' || node.dataset.ready === '1') return;
        node.dataset.ready = '1';
        const latitude = Number(node.dataset.lat);
        const longitude = Number(node.dataset.lng);
        const radius = Number(node.dataset.radius);
        const map = L.map(node).setView([latitude, longitude], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);
        L.marker([latitude, longitude]).addTo(map);
        L.circle([latitude, longitude], { radius: radius }).addTo(map);
    });
</script>
