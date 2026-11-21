<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\JobSite;
use App\Models\User;

class JobSitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([LogisticsPermission::GlobalAccess->value, LogisticsPermission::SuperviseSites->value]);
    }

    public function view(User $user, JobSite $site): bool
    {
        return $this->viewAny($user) && JobSite::visibleTo($user)->whereKey($site->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(LogisticsPermission::ManageSites->value);
    }

    public function update(User $user, JobSite $site): bool
    {
        return $this->create($user) && $this->view($user, $site);
    }

    public function delete(User $user, JobSite $site): bool
    {
        return $this->update($user, $site);
    }
}
