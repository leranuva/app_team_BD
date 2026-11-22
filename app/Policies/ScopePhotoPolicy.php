<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\JobScope;
use App\Models\ScopePhoto;
use App\Models\User;

/**
 * Carpenters and helpers cannot read or edit photos. Supervisor membership is
 * checked through JobScope::visibleTo(). Archiving preserves photo history.
 */
class ScopePhotoPolicy
{
    public function viewAny(User $user, ?JobScope $scope = null): bool
    {
        return $user->is_active
            && $user->canAny([LogisticsPermission::GlobalAccess->value, LogisticsPermission::SuperviseSites->value])
            && ($scope === null || JobScope::visibleTo($user)->whereKey($scope->id)->exists());
    }

    public function view(User $user, ScopePhoto $photo): bool
    {
        return $this->viewAny($user) && JobScope::visibleTo($user)->whereKey($photo->job_scope_id)->exists();
    }

    public function create(User $user, JobScope $scope): bool
    {
        return $this->viewAny($user, $scope);
    }

    public function archive(User $user, ScopePhoto $photo): bool
    {
        return $this->view($user, $photo);
    }

    public function restore(User $user, ScopePhoto $photo): bool
    {
        return $this->view($user, $photo);
    }

    public function delete(User $user, ScopePhoto $photo): bool
    {
        return false;
    }
}
