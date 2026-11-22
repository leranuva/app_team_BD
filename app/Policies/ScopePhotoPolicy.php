<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\JobScope;
use App\Models\ScopePhoto;
use App\Models\User;

/**
 * Carpintero y Ayudante no consultan ni modifican fotos. La membresía del supervisor
 * se comprueba en job_site_user, a través de JobScope::visibleTo(). Nadie puede borrar
 * una foto: el historial se conserva archivándola.
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
