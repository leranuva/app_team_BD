<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterMaterialListConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'consumed' => ['required', 'array', 'min:1'],
            'consumed.*' => ['numeric', 'min:0'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }
}
