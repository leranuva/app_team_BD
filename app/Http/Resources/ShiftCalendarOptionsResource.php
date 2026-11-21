<?php

namespace App\Http\Resources;

use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Models\JobSite;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftCalendarOptionsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'job_sites' => JobSite::visibleTo($user)->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'statuses' => array_map(fn (ShiftStatus $status) => ['value' => $status->value, 'label' => $status->label()], ShiftStatus::cases()),
            'roles' => array_map(fn (ShiftRole $role) => ['value' => $role->value, 'label' => $role->label()], ShiftRole::cases()),
            'can_edit' => $user->can('create', Shift::class),
        ];
    }
}
