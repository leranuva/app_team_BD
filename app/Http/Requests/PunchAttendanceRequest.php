<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;

class PunchAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Attendance::class);
    }

    public function rules(): array
    {
        return [
            'shift_id' => [$this->routeIs('api.attendances.clock-out') ? 'required' : 'required_without:job_site_id',
                'integer', 'min:1', 'prohibits:job_site_id'],
            'job_site_id' => ['sometimes', 'integer', 'min:1'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'between:0,9999999999.99'],
            'timestamp' => ['bail', 'required', 'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', 'date'],
        ];
    }
}
