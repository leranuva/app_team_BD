<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Enums\MaterialListStatus;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\User;
use App\Services\MaterialListStateMachine;

class MaterialListPolicy
{
    public function __construct(private readonly MaterialListStateMachine $machine) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ]);
    }

    public function view(User $user, MaterialList $list): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return MaterialList::query()->forUser($user)->whereKey($list->getKey())->exists();
    }

    public function create(User $user, ?JobSite $site = null): bool
    {
        if (! $user->is_active || ! $this->viewAny($user)) {
            return false;
        }

        if ($user->can(LogisticsPermission::GlobalAccess->value)) {
            return $site === null || JobSite::query()->whereKey($site->id)->exists();
        }

        if ($site === null) {
            return $user->can(LogisticsPermission::SuperviseSites->value);
        }

        return $site->users()->whereKey($user->id)->exists();
    }

    public function update(User $user, MaterialList $list): bool
    {
        return $this->view($user, $list)
            && $list->isEditable()
            && ($this->admin($user) || $list->requesterId() === (int) $user->getKey());
    }

    public function delete(User $user, MaterialList $list): bool
    {
        return false;
    }

    public function forceDelete(User $user, MaterialList $list): bool
    {
        return $this->admin($user) && $this->view($user, $list);
    }

    public function submit(User $user, MaterialList $list): bool
    {
        return $this->view($user, $list) && $this->machine->actorMay($list->status, MaterialListStatus::Submitted, $user, $list);
    }

    public function approve(User $user, MaterialList $list): bool
    {
        return $this->view($user, $list) && $this->machine->actorMay($list->status, MaterialListStatus::Approved, $user, $list);
    }

    public function markOrdered(User $user, MaterialList $list): bool
    {
        return $this->view($user, $list) && $this->machine->actorMay($list->status, MaterialListStatus::Ordered, $user, $list);
    }

    public function registerDelivery(User $user, MaterialList $list): bool
    {
        return $this->view($user, $list) && $this->machine->actorMay($list->status, MaterialListStatus::Delivered, $user, $list);
    }

    public function registerConsumption(User $user, MaterialList $list): bool
    {
        if (! $this->view($user, $list)) {
            return false;
        }

        if (! in_array($list->status, [MaterialListStatus::Delivered, MaterialListStatus::PartiallyConsumed], true)) {
            return false;
        }

        return $this->admin($user) || ($user->can(LogisticsPermission::SuperviseSites->value) && $list->actorBelongsToSite($user));
    }

    public function cancel(User $user, MaterialList $list): bool
    {
        return $this->view($user, $list) && $this->machine->actorMay($list->status, MaterialListStatus::Cancelled, $user, $list);
    }

    private function admin(User $user): bool
    {
        return $user->can(LogisticsPermission::GlobalAccess->value);
    }
}
