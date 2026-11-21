<?php

namespace App\Http\Requests;

class UpdateShiftRequest extends StoreShiftRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('shift'));
    }
}
