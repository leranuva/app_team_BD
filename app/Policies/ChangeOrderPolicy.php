<?php

namespace App\Policies;

use App\Enums\ChangeOrderStatus;
use App\Enums\LogisticsPermission;
use App\Models\ChangeOrder;
use App\Models\JobSite;
use App\Models\User;
use App\Services\ChangeOrderStateMachine;

class ChangeOrderPolicy
{
    public function __construct(private readonly ChangeOrderStateMachine $machine) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ]);
    }

    public function view(User $user, ChangeOrder $order): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->can(LogisticsPermission::GlobalAccess->value)) {
            return true;
        }

        return $order->actorBelongsToSite($user);
    }

    public function create(User $user, ?JobSite $site = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $manager = $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::ManageSites->value,
        ]);

        if ($site === null) {
            return $manager || $user->can(LogisticsPermission::SuperviseSites->value);
        }

        if ($manager) {
            return JobSite::visibleTo($user)->whereKey($site->id)->exists();
        }

        return $user->can(LogisticsPermission::SuperviseSites->value) && $site->users()->whereKey($user->id)->exists();
    }

    public function update(User $user, ChangeOrder $order): bool
    {
        return $this->view($user, $order)
            && $order->isEditable()
            && ($this->manages($user) || $order->requesterId() === (int) $user->getKey());
    }

    public function delete(User $user, ChangeOrder $order): bool
    {
        return false;
    }

    public function forceDelete(User $user, ChangeOrder $order): bool
    {
        return $this->manages($user) && $this->view($user, $order);
    }

    public function submit(User $user, ChangeOrder $order): bool
    {
        return $this->view($user, $order) && $this->machine->actorMay($order->status, ChangeOrderStatus::Submitted, $user, $order);
    }

    public function startReview(User $user, ChangeOrder $order): bool
    {
        return $this->view($user, $order) && $this->machine->actorMay($order->status, ChangeOrderStatus::UnderReview, $user, $order);
    }

    public function approve(User $user, ChangeOrder $order): bool
    {
        return $this->view($user, $order) && $this->machine->actorMay($order->status, ChangeOrderStatus::Approved, $user, $order);
    }

    public function reject(User $user, ChangeOrder $order): bool
    {
        return $this->view($user, $order) && $this->machine->actorMay($order->status, ChangeOrderStatus::Rejected, $user, $order);
    }

    public function cancel(User $user, ChangeOrder $order): bool
    {
        return $this->view($user, $order) && $this->machine->actorMay($order->status, ChangeOrderStatus::Cancelled, $user, $order);
    }

    private function manages(User $user): bool
    {
        return $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::ManageSites->value,
        ]);
    }
}
