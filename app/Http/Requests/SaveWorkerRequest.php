<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveWorkerRequest extends FormRequest
{
    public function rules(): array
    {
        return self::profileRules($this->route('worker'), $this->isMethod('POST'));
    }

    public static function contactRules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact' => ['nullable', 'array:name,phone'],
            'emergency_contact.name' => ['nullable', 'required_with:emergency_contact.phone', 'string', 'max:255'],
            'emergency_contact.phone' => ['nullable', 'required_with:emergency_contact.name', 'string', 'max:40'],
        ];
    }

    public static function profileRules(?User $worker, bool $creating): array
    {
        return self::contactRules() + [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($worker?->getKey())],
            'password' => [$creating ? 'required' : 'prohibited', 'nullable', Password::min(12), 'max:72'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')
                ->where('guard_name', 'web')->whereIn('name', User::operationalRoles())],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'insurance_reference' => ['nullable', 'string', 'max:255'],
            'primary_job_site_id' => ['nullable', 'integer', Rule::exists('job_sites', 'id')->whereNull('deleted_at')],
            'identity_documents' => ['nullable', 'array', 'max:5'],
            'identity_documents.*' => ['bail', 'string', self::documentRule('identity')],
            'osha_documents' => ['nullable', 'array', 'max:5'],
            'osha_documents.*' => ['bail', 'string', self::documentRule('osha')],
        ];
    }

    private static function documentRule(string $kind): \Closure
    {
        return static function (string $attribute, string $path, \Closure $fail) use ($kind): void {
            $prefix = "worker-documents/{$kind}/";

            if (! Str::startsWith($path, $prefix) || ! preg_match('/^[A-Za-z0-9_-]+\.(pdf|jpe?g|png)$/i', Str::after($path, $prefix))) {
                $fail('The selected document is invalid.');
            }
        };
    }
}
