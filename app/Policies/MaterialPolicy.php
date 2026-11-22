<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\Material;
use App\Models\User;

/**
 * Super admins and administrators manage the catalog. Supervisors can browse it
 * to build lists. Materials referenced by a list cannot be deleted.
 */
class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, Material $material): bool
    {
        return $this->canRead($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Material $material): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, Material $material): bool
    {
        if (! $this->canWrite($user)) {
            return false;
        }

        $count = Material::query()->withCount('listItems')->find($material->getKey())?->list_items_count;

        return $count === 0;
    }

    public function import(User $user): bool
    {
        return $this->canWrite($user);
    }

    private function canRead(User $user): bool
    {
        return $user->is_active && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ]);
    }

    private function canWrite(User $user): bool
    {
        return $user->is_active && $user->can(LogisticsPermission::GlobalAccess->value);
    }
}
