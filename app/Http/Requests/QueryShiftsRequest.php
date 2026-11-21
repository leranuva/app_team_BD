<?php

namespace App\Http\Requests;

use App\Enums\LogisticsPermission;
use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Models\JobSite;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QueryShiftsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Shift::class);
    }

    public function rules(): array
    {
        $isoDate = ['bail', 'required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2}))?$/D', 'date'];

        return [
            'from' => $isoDate,
            'to' => $isoDate,
            'job_site_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'worker_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'role_required' => ['sometimes', 'required', Rule::enum(ShiftRole::class)],
            'status' => ['sometimes', 'required', Rule::enum(ShiftStatus::class)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->boundary('from');
            $to = $this->boundary('to');

            if ($to->lessThan($from)) {
                $validator->errors()->add('to', 'El fin del rango debe ser igual o posterior al inicio.');
            } elseif ($to->greaterThan($from->addDays(90))) {
                $validator->errors()->add('to', 'El rango puede abarcar como máximo 90 días.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        $user = $this->user();
        if ($user->can(LogisticsPermission::GlobalAccess->value)) {
            return;
        }

        if ($user->can(LogisticsPermission::SuperviseSites->value)) {
            abort_if($this->filled('job_site_id') && ! JobSite::visibleTo($user)->whereKey($this->integer('job_site_id'))->exists(), 403);

            return;
        }

        abort_if($this->filled('worker_id') && $this->integer('worker_id') !== $user->id, 403);
    }

    public function boundary(string $field): CarbonImmutable
    {
        $timezone = config('app.business_timezone');

        return CarbonImmutable::parse($this->input($field), $timezone)->setTimezone($timezone);
    }
}
