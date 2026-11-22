<?php

namespace App\Services;

use App\Enums\ChangeOrderStatus;
use App\Enums\LogisticsPermission;
use App\Exceptions\InvalidChangeOrderTransitionException;
use App\Models\ChangeOrder;
use App\Models\User;

class ChangeOrderStateMachine
{
    public function allowed(ChangeOrderStatus $from): array
    {
        return match ($from) {
            ChangeOrderStatus::Draft => [ChangeOrderStatus::Submitted, ChangeOrderStatus::Cancelled],
            ChangeOrderStatus::Submitted => [ChangeOrderStatus::UnderReview, ChangeOrderStatus::Cancelled],
            ChangeOrderStatus::UnderReview => [ChangeOrderStatus::Approved, ChangeOrderStatus::Rejected, ChangeOrderStatus::Cancelled],
            ChangeOrderStatus::Approved, ChangeOrderStatus::Rejected, ChangeOrderStatus::Cancelled => [],
        };
    }

    public function assertAvailable(ChangeOrderStatus $from, ChangeOrderStatus $to): void
    {
        $allowed = $this->allowed($from);
        if (! in_array($to, $allowed, true)) {
            throw new InvalidChangeOrderTransitionException(
                $from->value,
                $to->value,
                array_map(fn (ChangeOrderStatus $status): string => $status->value, $allowed),
            );
        }
    }

    public function actorMay(ChangeOrderStatus $from, ChangeOrderStatus $to, User $actor, ChangeOrder $order): bool
    {
        if (! $actor->is_active || ! in_array($to, $this->allowed($from), true)) {
            return false;
        }

        $admin = $actor->can(LogisticsPermission::GlobalAccess->value) || $actor->can(LogisticsPermission::ManageSites->value);
        $supervisor = $actor->can(LogisticsPermission::SuperviseSites->value) && $order->actorBelongsToSite($actor);
        $requester = $order->requesterId() === (int) $actor->getKey();
        $withinThreshold = abs($order->amount_cents) <= (int) config('change_orders.supervisor_approval_threshold_cents');

        return match ($to) {
            ChangeOrderStatus::Submitted => $requester || $admin,
            ChangeOrderStatus::UnderReview, ChangeOrderStatus::Rejected => $admin || $supervisor,
            ChangeOrderStatus::Approved => $admin || ($supervisor && $withinThreshold),
            ChangeOrderStatus::Cancelled => $admin,
            default => false,
        };
    }
}
