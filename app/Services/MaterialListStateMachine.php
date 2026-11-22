<?php

namespace App\Services;

use App\Enums\LogisticsPermission;
use App\Enums\MaterialListStatus;
use App\Exceptions\InvalidMaterialListTransitionException;
use App\Models\MaterialList;
use App\Models\User;

class MaterialListStateMachine
{
    /**
     * @return list<MaterialListStatus>
     */
    public function allowed(MaterialListStatus $from): array
    {
        return match ($from) {
            MaterialListStatus::Draft => [MaterialListStatus::Submitted, MaterialListStatus::Cancelled],
            MaterialListStatus::Submitted => [MaterialListStatus::Approved, MaterialListStatus::Cancelled],
            MaterialListStatus::Approved => [MaterialListStatus::Ordered, MaterialListStatus::Cancelled],
            MaterialListStatus::Ordered => [MaterialListStatus::Delivered, MaterialListStatus::Cancelled],
            MaterialListStatus::Delivered => [MaterialListStatus::PartiallyConsumed, MaterialListStatus::Consumed],
            MaterialListStatus::PartiallyConsumed => [MaterialListStatus::Consumed],
            MaterialListStatus::Consumed, MaterialListStatus::Cancelled, MaterialListStatus::Pending => [],
        };
    }

    public function assertAvailable(MaterialListStatus $from, MaterialListStatus $to): void
    {
        $allowed = $this->allowed($from);
        if (! in_array($to, $allowed, true)) {
            throw new InvalidMaterialListTransitionException(
                $from->value,
                $to->value,
                array_map(fn (MaterialListStatus $status): string => $status->value, $allowed),
            );
        }
    }

    public function actorMay(MaterialListStatus $from, MaterialListStatus $to, User $actor, MaterialList $list): bool
    {
        if (! $actor->is_active || ! in_array($to, $this->allowed($from), true)) {
            return false;
        }

        $admin = $actor->can(LogisticsPermission::GlobalAccess->value);
        $supervisor = $actor->can(LogisticsPermission::SuperviseSites->value) && $list->actorBelongsToSite($actor);
        $requester = $list->requesterId() === (int) $actor->getKey();
        $total = $list->totalRequestedValueCents();
        $withinThreshold = $total !== null && $total <= (int) config('material_lists.supervisor_approval_threshold_cents');

        return match ($to) {
            MaterialListStatus::Submitted => $requester || $admin,
            MaterialListStatus::Approved => $admin || ($supervisor && $withinThreshold),
            MaterialListStatus::Ordered, MaterialListStatus::Delivered, MaterialListStatus::PartiallyConsumed, MaterialListStatus::Consumed => $admin || $supervisor,
            MaterialListStatus::Cancelled => match ($from) {
                MaterialListStatus::Draft, MaterialListStatus::Submitted => $requester || $admin,
                MaterialListStatus::Approved, MaterialListStatus::Ordered => $admin,
                default => false,
            },
            default => false,
        };
    }
}
