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
            'file.required' => 'Selecciona una foto.',
            'file.image' => 'Sube una imagen JPEG, PNG o WebP.',
            'file.mimes' => 'Sube una imagen JPEG, PNG o WebP.',
            'file.max' => 'La foto no puede superar 10 MB.',
        ];
    }
}
