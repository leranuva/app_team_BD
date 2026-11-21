<?php

namespace App\Http\Resources;

use App\Enums\ShiftRole;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function __construct($resource, private readonly ?bool $canEdit = null)
    {
        parent::__construct($resource);
    }

    public static function relations(): array
    {
        return [
            'jobSite:id,name,address',
            'worker:id,name',
            'worker.roles:id,name',
            'requiredRole:id,name',
            'jobScope' => fn ($scope) => $scope->withoutGlobalScope(LogisticsVisibilityScope::class)->select('id', 'name', 'supervisor_id'),
            'jobScope.supervisor:id,name',
        ];
    }

    public function toArray(Request $request): array
    {
        $timezone = config('app.business_timezone');
        $role = $this->effectiveRole();
        $workerRole = collect(ShiftRole::cases())->first(fn (ShiftRole $candidate) => $this->worker?->hasRole($candidate->label()));
        $titleRole = $workerRole ?? $role;

        return [
            'id' => $this->id,
            'title' => implode(' · ', array_filter([$this->worker?->name, $titleRole?->label()])),
            'start' => $this->starts_at->setTimezone($timezone)->toIso8601String(),
            'end' => $this->ends_at->setTimezone($timezone)->toIso8601String(),
            'updated_at' => $this->updated_at?->setTimezone($timezone)->toIso8601String(),
            'status' => $this->status->value,
            'role_required' => $role?->value,
            'job_site' => $this->jobSite?->only(['id', 'name', 'address']),
            'job_scope' => $this->jobScope?->only(['id', 'name']),
            'supervisor_name' => $this->jobScope?->supervisor?->name,
            'notes' => $this->notes,
            'worker' => $this->worker ? $this->worker->only(['id', 'name']) + ['role' => $workerRole?->value] : null,
            'extendedProps' => [
                'worker_name' => $this->worker?->name,
                'job_site_name' => $this->jobSite?->name,
                'role_required' => $role?->value,
                'status' => $this->status->value,
                'can_edit' => $this->canEdit ?? ($request->user()?->can('update', $this->resource) ?? false),
            ],
        ];
    }
}
