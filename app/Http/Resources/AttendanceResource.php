<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $zone = config('app.business_timezone');

        return [
            'id' => $this->id,
            'shift_id' => $this->shift_id,
            'job_site_id' => $this->job_site_id,
            'clocked_in_at' => $this->clocked_in_at->setTimezone($zone)->toIso8601String(),
            'clocked_out_at' => $this->clocked_out_at?->setTimezone($zone)->toIso8601String(),
            'geofence_flagged' => $this->geofence_flagged,
            'duration_minutes' => $this->duration_minutes,
            'regular_minutes' => $this->regular_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'double_time_minutes' => $this->double_time_minutes,
        ];
    }
}
