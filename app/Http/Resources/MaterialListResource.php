<?php

namespace App\Http\Resources;

use App\Models\MaterialList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class MaterialListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MaterialList $list */
        $list = $this->resource;
        $user = $request->user() ?: Auth::user();
        $zone = config('app.business_timezone');
        $person = fn ($related): ?array => $related === null ? null : ['id' => $related->id, 'name' => $related->name];
        $at = fn ($value): ?string => $value?->timezone($zone)->toIso8601String();

        return [
            'id' => $list->id,
            'code' => $list->code,
            'title' => $list->title ?? $list->name,
            'status' => $list->status->value,
            'notes' => $list->notes,
            'job_site' => $list->jobSite === null ? null : ['id' => $list->jobSite->id, 'name' => $list->jobSite->name],
            'job_scope' => $list->jobScope === null ? null : ['id' => $list->jobScope->id, 'name' => $list->jobScope->name],
            'requested_by' => $person($list->requestedBy),
            'approved_by' => $person($list->approvedBy),
            'ordered_at' => $at($list->ordered_at),
            'delivered_at' => $at($list->delivered_at),
            'consumed_at' => $at($list->consumed_at),
            'cancelled_reason' => $list->cancelled_reason,
            'total_requested_value_cents' => $list->totalRequestedValueCents(),
            'items' => $list->items->map(fn ($item): array => [
                'id' => $item->id,
                'budgeted_quantity' => $item->budgeted_quantity,
                'requested_quantity' => $item->requested_quantity,
                'approved_quantity' => $item->approved_quantity,
                'delivered_quantity' => $item->delivered_quantity,
                'consumed_quantity' => $item->consumed_quantity,
                'unit_cost_cents' => $item->unit_cost_cents,
                'notes' => $item->notes,
                'material' => $item->material === null ? null : [
                    'id' => $item->material->id,
                    'code' => $item->material->code,
                    'name' => $item->material->name,
                    'unit' => $item->material->unit,
                ],
            ])->all(),
            'can_submit' => $user !== null && $user->can('submit', $list),
            'can_approve' => $user !== null && $user->can('approve', $list),
            'can_mark_ordered' => $user !== null && $user->can('markOrdered', $list),
            'can_register_delivery' => $user !== null && $user->can('registerDelivery', $list),
            'can_register_consumption' => $user !== null && $user->can('registerConsumption', $list),
            'can_cancel' => $user !== null && $user->can('cancel', $list),
        ];
    }
}
