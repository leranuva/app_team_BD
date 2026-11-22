<?php

namespace App\Http\Requests;

use App\Models\JobScope;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJobScopeProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->find($this->route('scope'));

        return $record === null || ($this->user()?->can('update', $record) ?? false);
    }

    public function rules(): array
    {
        return [
            'progress' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
