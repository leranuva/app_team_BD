<?php

namespace App\Http\Resources;

use App\Models\ChangeOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ChangeOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ChangeOrder $order */
        $order = $this->resource;
        $user = $request->user() ?: Auth::user();
        $zone = config('app.business_timezone');
        $person = fn ($related): ?array => $related === null ? null : ['id' => $related->id, 'name' => $related->name];
        $at = fn ($value): ?string => $value?->timezone($zone)->toIso8601String();

        return [
            'id' => $order->id,
            'code' => $order->code,
            'title' => $order->title,
            'description' => $order->description,
            'status' => $order->status->value,
            'amount_cents' => $order->amount_cents,
            'amount_dollars' => $order->amountInDollars(),
            'currency' => $order->currency,
            'time_impact_days' => $order->time_impact_days,
            'schedule_impact_note' => $order->schedule_impact_note,
            'job_site' => $order->jobSite === null ? null : ['id' => $order->jobSite->id, 'name' => $order->jobSite->name],
            'job_scope' => $order->jobScope === null ? null : ['id' => $order->jobScope->id, 'name' => $order->jobScope->name],
            'requested_by' => $person($order->requestedBy),
            'reviewed_by' => $person($order->reviewedBy),
            'approved_by' => $person($order->approvedBy),
            'submitted_at' => $at($order->submitted_at),
            'reviewed_at' => $at($order->reviewed_at),
            'approved_at' => $at($order->approved_at),
            'rejected_at' => $at($order->rejected_at),
            'rejection_reason' => $order->rejection_reason,
            'cancelled_reason' => $order->cancelled_reason,
            'attachments' => ($order->relationLoaded('attachments') ? $order->getRelation('attachments') : $order->attachments()->get())->map(fn ($file): array => [
                'original_name' => $file->original_name,
                'url' => $file->temporaryUrl(),
            ])->all(),
            'can_submit' => (bool) $user?->can('submit', $order),
            'can_review' => (bool) $user?->can('startReview', $order),
            'can_approve' => (bool) $user?->can('approve', $order),
            'can_reject' => (bool) $user?->can('reject', $order),
            'can_cancel' => (bool) $user?->can('cancel', $order),
        ];
    }
}
