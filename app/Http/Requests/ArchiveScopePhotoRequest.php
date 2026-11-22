<?php

namespace App\Http\Requests;

use App\Models\ScopePhoto;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveScopePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->photo());
    }

    public function photo(): ScopePhoto
    {
        return ScopePhoto::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($this->route('photo'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('reason') === '') {
            $this->merge(['reason' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
