<div class="h-2 w-24 overflow-hidden rounded-full bg-gray-200" aria-hidden="true">
    <div class="h-full bg-gray-950" style="width: {{ max(0, min(100, (int) $getState())) }}%"></div>
</div>
<span class="text-xs">{{ (int) $getState() }}%</span>
