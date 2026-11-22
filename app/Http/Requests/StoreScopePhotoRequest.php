<?php

namespace App\Http\Requests;

use App\Models\JobScope;
use App\Models\ScopePhoto;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Foundation\Http\FormRequest;

class StoreScopePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ScopePhoto::class, $this->scope()]);
    }

    public function scope(): JobScope
    {
        return JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($this->route('scope'));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'taken_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Select a photo.',
            'file.image' => 'Upload a JPEG, PNG or WebP image.',
            'file.mimes' => 'Upload a JPEG, PNG or WebP image.',
            'file.max' => 'The photo must be 10 MB or smaller.',
        ];
    }
}
