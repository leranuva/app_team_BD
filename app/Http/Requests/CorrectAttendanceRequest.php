<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use App\Models\Scopes\LogisticsVisibilityScope;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class CorrectAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)->find($this->route('attendance'));

        return $record === null || ($this->user()?->can('correct', $record) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['clock_in_at', 'clock_out_at'] as $field) {
            $value = $this->input($field);
            if (! is_string($value) || $value === '') {
                continue;
            }
            try {
                $normalized[$field] = Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
            } catch (Throwable) {
                // The date rule reports the original value.
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        $instant = ['string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', 'date'];

        return [
            'clock_in_at' => ['bail', 'required', ...$instant],
            'clock_out_at' => ['bail', 'required', ...$instant, 'after:clock_in_at'],
            'correction_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'correction_reason.required' => 'El motivo de la corrección es obligatorio.',
            'correction_reason.min' => 'El motivo debe tener al menos 10 caracteres.',
            'clock_out_at.after' => 'La salida debe ser posterior a la entrada.',
        ];
    }
}
