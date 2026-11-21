<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Shift;
use App\Models\User;

class AttendancePolicy extends LogisticsRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && parent::viewAny($user);
    }

    public function clockIn(User $user, Shift $shift): bool
    {
        return $user->is_active && $user->id === $shift->user_id
            && $user->can('view', $shift);
    }

    public function clockOut(User $user, Attendance $attendance): bool
    {
        return $this->viewAny($user) && $user->id === $attendance->user_id;
    }

    public function clockOutShift(User $user, Shift $shift): bool
    {
        return $this->viewAny($user) && $user->id === $shift->user_id;
    }
}
