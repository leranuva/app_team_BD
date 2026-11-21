<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->canAny([LogisticsPermission::GlobalAccess->value, LogisticsPermission::SuperviseSites->value]);
    }

    public function view(User $user, User $worker): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->is($worker) || $user->can(LogisticsPermission::GlobalAccess->value)) {
            return true;
        }

        return User::workersVisibleTo($user)->whereKey($worker->id)->exists();
    }

    public function viewPrivateDetails(User $user, User $worker): bool
    {
        return $user->is($worker) || $user->can(LogisticsPermission::GlobalAccess->value);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can(LogisticsPermission::GlobalAccess->value);
    }

    public function update(User $user, User $worker): bool
    {
        return $user->is_active && User::workersVisibleTo($user)->whereKey($worker->id)->exists();
    }

    public function updateContactInfo(User $user, User $worker): bool
    {
        return $user->is_active
            && $user->canAny([LogisticsPermission::GlobalAccess->value, LogisticsPermission::SuperviseSites->value])
            && User::workersVisibleTo($user)->whereKey($worker->id)->exists();
    }

    public function manageWorkerProfile(User $user, User $worker): bool
    {
        return $this->create($user) && $this->update($user, $worker);
    }

    public function delete(User $user, User $worker): bool
    {
        return $this->manageWorkerProfile($user, $worker);
    }
}
