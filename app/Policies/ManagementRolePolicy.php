<?php

namespace App\Policies;

use App\Enums\LogisticsRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

class ManagementRolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(LogisticsRole::SuperAdmin->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->viewAny($user) && LogisticsRole::tryFrom($role->name) === null;
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
