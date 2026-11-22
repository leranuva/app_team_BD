<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ScopePhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = config('app.business_timezone');
        $url = $this->temporaryUrl();
        $user = $request->user() ?: Auth::user();

        return [
            'id' => $this->id,
            'job_scope_id' => $this->job_scope_id,
            'caption' => $this->caption,
            'url' => $url,
            'thumbnail_url' => $url,
            'taken_at' => $this->taken_at?->setTimezone($timezone)->toIso8601String(),
            'uploaded_by' => $this->uploadedBy?->only(['id', 'name']),
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
            'archived_at' => $this->archived_at?->setTimezone($timezone)->toIso8601String(),
            'can_archive' => $this->archived_at === null && ($user?->can('archive', $this->resource) ?? false),
            'can_restore' => $this->archived_at !== null && ($user?->can('restore', $this->resource) ?? false),
        ];
    }
}
