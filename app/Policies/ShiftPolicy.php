<?php

namespace App\Policies;

use App\Enums\LogisticsPermission;
use App\Enums\ShiftStatus;
use App\Models\JobSite;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
            LogisticsPermission::ViewAssignments->value,
        ]);
    }

    public function view(User $user, Shift $shift): bool
    {
        return $this->viewAny($user) && Shift::forUser($user)->whereKey($shift->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ]);
    }

    public function assignToSite(User $user, JobSite $site): bool
    {
        return $this->create($user) && JobSite::visibleTo($user)->whereKey($site->id)->exists();
    }

    public function update(User $user, Shift $shift): bool
    {
        return $this->editableShifts($user)->whereKey($shift->id)->exists();
    }

    public function editableShifts(User $user): Builder
    {
        $query = Shift::forUser($user);
        if (! $this->create($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('status', '!=', ShiftStatus::Cancelled->value)
            ->where('is_unscheduled', false)
            ->whereHas('user', fn (Builder $workers) => $workers->active()
                ->whereHas('jobSites', fn (Builder $sites) => $sites
                    ->withoutGlobalScope(LogisticsVisibilityScope::class)
                    ->whereColumn('job_sites.id', 'shifts.job_site_id')));
    }

    public function cancel(User $user, Shift $shift): bool
    {
        return $this->update($user, $shift)
            && in_array($shift->status, [ShiftStatus::Programmed, ShiftStatus::Confirmed], true);
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->can(LogisticsPermission::GlobalAccess->value) && $this->view($user, $shift)
            && ! $shift->attendance()->withoutGlobalScopes()->exists();
    }
}
