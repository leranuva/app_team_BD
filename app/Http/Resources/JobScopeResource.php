<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class JobScopeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user() ?: Auth::user();

        return [
            'id' => $this->id,
            'job_site' => $this->jobSite === null ? null : [
                'id' => $this->jobSite->id,
                'name' => $this->jobSite->name,
            ],
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'progress_percent' => (int) $this->progress,
            'responsible' => $this->supervisor === null ? null : [
                'id' => $this->supervisor->id,
                'name' => $this->supervisor->name,
            ],
            'start_date' => $this->start_date?->toDateString(),
            'estimated_end_date' => $this->estimated_end_date?->toDateString(),
            'photos_count' => (int) ($this->photos_count ?? 0),
            'shifts_count' => (int) ($this->shifts_count ?? 0),
            'can_edit' => (bool) $user?->can('update', $this->resource),
        ];
    }
}
