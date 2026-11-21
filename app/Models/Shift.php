<?php

namespace App\Models;

use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Models\Concerns\HasLogisticsVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role;

class Shift extends Model
{
    use HasFactory, HasLogisticsVisibility, LogsActivity, SoftDeletes;

    protected $attributes = ['status' => 'programmed'];

    protected $fillable = ['job_site_id', 'user_id', 'required_role_id', 'starts_at', 'ends_at', 'job_scope_id', 'role_required', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => ShiftStatus::class,
            'role_required' => ShiftRole::class,
        ];
    }

    public function freshTimestamp()
    {
        $now = parent::freshTimestamp()->startOfSecond();
        $previous = $this->exists ? $this->getOriginal('updated_at') : null;

        // The existing database column has second precision.
        return $previous && $previous->greaterThanOrEqualTo($now)
            ? $previous->copy()->addSecond()
            : $now;
    }

    public function jobSite(): BelongsTo
    {
        return $this->belongsTo(JobSite::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function jobScope(): BelongsTo
    {
        return $this->belongsTo(JobScope::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $user->is_active ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('starts_at', '<', $end->avoidMutation()->utc())
            ->where('ends_at', '>', $start->avoidMutation()->utc());
    }

    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function effectiveRole(): ?ShiftRole
    {
        if ($this->role_required !== null) {
            return $this->role_required;
        }

        $legacyRole = $this->requiredRole?->name;

        foreach (ShiftRole::cases() as $role) {
            if ($role->label() === $legacyRole) {
                return $role;
            }
        }

        return null;
    }

    public function scopeWithRequiredRole(Builder $query, ShiftRole $role): Builder
    {
        return $query->where(fn (Builder $roles) => $roles->where('role_required', $role->value)
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('role_required')
                ->whereHas('requiredRole', fn (Builder $required) => $required->where('name', $role->label()))));
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('shifts')
            ->logOnly(['job_site_id', 'user_id', 'required_role_id', 'starts_at', 'ends_at', 'job_scope_id',
                'role_required', 'status', 'notes', 'cancellation_reason', 'created_by', 'updated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
