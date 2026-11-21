<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\Material;
use App\Models\User;

class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(LogisticsPermission::GlobalAccess->value);
    }

    public function view(User $user, Material $material): bool
    {
        return $this->viewAny($user);
    }
}
