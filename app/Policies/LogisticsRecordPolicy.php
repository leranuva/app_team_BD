<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LogisticsRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
            LogisticsPermission::ViewAssignments->value,
        ]);
    }

    public function view(User $user, Model $record): bool
    {
        return $record->newQuery()->visibleTo($user)->whereKey($record->getKey())->exists();
    }
}
