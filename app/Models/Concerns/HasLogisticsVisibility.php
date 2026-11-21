<?php

namespace App\Models\Concerns;

use App\Enums\LogisticsPermission;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait HasLogisticsVisibility
{
    public static function bootHasLogisticsVisibility(): void
    {
        static::addGlobalScope(new LogisticsVisibilityScope);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->withoutGlobalScope(LogisticsVisibilityScope::class);

        if ($this instanceof MaterialListItem) {
            return $query->whereHas('materialList', fn (Builder $lists) => $lists->visibleTo($user));
        }

        if ($this instanceof JobSite) {
            return $this->visibleSites($query, $user);
        }

        $query->whereHas('jobSite', fn (Builder $sites) => $sites->visibleTo($user));

        if ($user->can(LogisticsPermission::GlobalAccess->value) || $user->can(LogisticsPermission::SuperviseSites->value)) {
            return $query;
        }

        if (! $user->can(LogisticsPermission::ViewAssignments->value)) {
            return $query->whereRaw('1 = 0');
        }

        return match (true) {
            $this instanceof Shift, $this instanceof Attendance => $query->where($this->qualifyColumn('user_id'), $user->getKey()),
            $this instanceof MaterialList => $query->where($this->qualifyColumn('assigned_to'), $user->getKey()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function visibleSites(Builder $query, User $user): Builder
    {
        if ($user->can(LogisticsPermission::GlobalAccess->value)) {
            return $query;
        }

        if ($user->can(LogisticsPermission::SuperviseSites->value)) {
            return $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()));
        }

        if ($user->can(LogisticsPermission::ViewAssignments->value)) {
            return $query->where(fn (Builder $sites) => $sites
                ->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()))
                ->orWhere($this->qualifyColumn('id'), $user->primary_job_site_id));
        }

        return $query->whereRaw('1 = 0');
    }
}
