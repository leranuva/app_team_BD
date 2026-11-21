<?php

namespace App\Http\Requests;

use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Shift::class);
    }

    public function rules(): array
    {
        return self::fields();
    }

    public static function fields(): array
    {
        return [
            'job_site_id' => ['required', 'integer', Rule::exists('job_sites', 'id')->whereNull('deleted_at')],
            'job_scope_id' => ['nullable', 'integer'],
            'role_required' => ['required', Rule::enum(ShiftRole::class)],
            'user_id' => ['required', 'integer'],
            'starts_at' => ['bail', 'required', 'date'],
            'ends_at' => ['bail', 'required', 'date', 'after:starts_at'],
            'status' => ['required', Rule::enum(ShiftStatus::class)->except(ShiftStatus::Cancelled)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
