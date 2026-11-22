<?php

namespace App\Models;

use App\Enums\LogisticsPermission;
use App\Enums\LogisticsRole;
use App\Models\Concerns\HasLogisticsVisibility;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobSite extends Model
{
    use HasFactory, HasLogisticsVisibility, SoftDeletes;

    protected $fillable = [
        'name', 'address', 'client', 'latitude', 'longitude', 'geofence_meters', 'status',
        'base_budget', 'start_date', 'estimated_end_date', 'notes',
        'approved_change_orders_cents', 'approved_time_impact_days',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'base_budget' => 'decimal:2',
            'approved_adjustment' => 'decimal:2',
            'geofence_meters' => 'integer',
            'start_date' => 'date',
            'estimated_end_date' => 'date',
            'approved_change_orders_cents' => 'integer',
            'approved_time_impact_days' => 'integer',
        ];
    }

    public function scopeReadableBy(Builder $query, User $user): Builder
    {
        if ($user->can(LogisticsPermission::GlobalAccess->value) || $user->can(LogisticsPermission::SuperviseSites->value)) {
            return $query->visibleTo($user);
        }

        $query->withoutGlobalScope(LogisticsVisibilityScope::class);

        if (! $user->can(LogisticsPermission::ViewAssignments->value)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($this->qualifyColumn('id'), Shift::query()
            ->withoutGlobalScope(LogisticsVisibilityScope::class)
            ->where('user_id', $user->getKey())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where('ends_at', '>=', now())
            ->select('job_site_id'));
    }

    public function scopeWithOperationalCounts(Builder $query): Builder
    {
        return $query->withCount([
            'scopes as active_scopes_count' => fn (Builder $scopes) => $scopes->where('status', 'active'),
            'photos as active_photos_count' => fn (Builder $photos) => $photos->active(),
        ]);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class);
    }

    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps()
            ->whereHas('roles', fn (Builder $roles) => $roles
                ->where('name', LogisticsRole::Supervisor->value)
                ->where('guard_name', 'web'));
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ScopePhoto::class);
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(JobScope::class);
    }

    public function materialLists(): HasMany
    {
        return $this->hasMany(MaterialList::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
