<?php

namespace App\Http\Requests;

use App\Models\JobScope;
use App\Models\ScopePhoto;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueryScopePhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', [ScopePhoto::class, $this->scope()]);
    }

    public function scope(): JobScope
    {
        return JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($this->route('scope'));
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('status')) {
            $this->merge(['status' => 'active']);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['active', 'archived', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
