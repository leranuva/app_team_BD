<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterMaterialListDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'delivered' => ['required', 'array', 'min:1'],
            'delivered.*' => ['numeric', 'min:0'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }
}
