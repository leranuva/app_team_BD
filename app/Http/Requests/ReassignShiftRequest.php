<?php

namespace App\Http\Requests;

use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReassignShiftRequest extends FormRequest
{
    private const ISO_TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D';

    private ?Shift $selectedShift = null;

    /** @var array<string, CarbonImmutable> */
    private array $instants = [];

    protected function prepareForValidation(): void
    {
        foreach (['start_at', 'end_at', 'expected_updated_at'] as $field) {
            $value = $this->input($field);
            if (! is_string($value) || ! preg_match(self::ISO_TIMESTAMP, $value)) {
                continue;
            }

            $parts = date_parse($value);
            if ($parts['error_count'] === 0 && $parts['warning_count'] === 0) {
                $this->instants[$field] = CarbonImmutable::parse($value, config('app.business_timezone'))->utc();
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->shift());
    }

    public function rules(): array
    {
        $timestamp = ['bail', 'required', 'string', 'regex:'.self::ISO_TIMESTAMP, 'date'];

        return [
            'start_at' => $timestamp,
            'end_at' => [...$timestamp, 'after:start_at'],
            'expected_updated_at' => $timestamp,
            'job_site_id' => ['missing'],
            'worker_id' => ['missing'],
            'user_id' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return ['expected_updated_at.required' => 'Include the shift version to avoid overwriting changes'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['start_at', 'end_at', 'expected_updated_at'] as $field) {
                if (! isset($this->instants[$field]) && ! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, 'Enter a valid ISO 8601 date and time with a UTC offset.');
                }
            }
        }];
    }

    public function instant(string $field): CarbonImmutable
    {
        return $this->instants[$field];
    }

    public function shift(): Shift
    {
        return $this->selectedShift ??= Shift::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->findOrFail($this->route('shift'));
    }
}
