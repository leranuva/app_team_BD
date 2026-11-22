<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'job_scope_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.requested_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.budgeted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost_cents' => ['nullable', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
