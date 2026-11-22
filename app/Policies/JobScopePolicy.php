<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;

class JobScopePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ]);
    }

    public function view(User $user, JobScope $scope): bool
    {
        return $this->viewAny($user) && JobScope::visibleTo($user)->whereKey($scope->id)->exists();
    }

    public function create(User $user, ?JobSite $site = null): bool
    {
        if ($site === null) {
            return $user->is_active && $user->canAny([
                LogisticsPermission::ManageSites->value,
                LogisticsPermission::SuperviseSites->value,
            ]);
        }

        if ($user->can(LogisticsPermission::ManageSites->value)) {
            return JobSite::visibleTo($user)->whereKey($site->id)->exists();
        }

        return $user->can(LogisticsPermission::SuperviseSites->value)
            && JobSite::visibleTo($user)->whereKey($site->id)->exists();
    }

    public function update(User $user, JobScope $scope): bool
    {
        return $this->create($user, $scope->jobSite) && $this->view($user, $scope);
    }

    public function delete(User $user, JobScope $scope): bool
    {
        return $user->can(LogisticsPermission::ManageSites->value) && $this->view($user, $scope);
    }

    public function readAny(User $user): bool
    {
        return $user->can('readAny', JobSite::class);
    }

    public function read(User $user, JobScope $scope): bool
    {
        return $this->readAny($user) && JobScope::readableBy($user)->whereKey($scope->id)->exists();
    }
}
