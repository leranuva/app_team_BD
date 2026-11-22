<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_name' => $this->client,
            'address' => $this->address,
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
            'geofence_radius_m' => $this->geofence_meters,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'estimated_end_date' => $this->estimated_end_date?->toDateString(),
            'scopes_count' => (int) ($this->active_scopes_count ?? 0),
            'photos_count' => (int) ($this->active_photos_count ?? 0),
            'supervisors' => $this->whenLoaded('supervisors', fn () => $this->supervisors->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values()),
        ];
    }
}
