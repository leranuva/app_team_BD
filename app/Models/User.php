<?php

namespace App\Models;

use App\Enums\LogisticsPermission;
use App\Enums\LogisticsRole;
use App\Models\Scopes\LogisticsVisibilityScope;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'email', 'password', 'phone', 'address', 'insurance_reference', 'hourly_rate',
        'emergency_contact', 'primary_job_site_id', 'identity_documents', 'osha_documents'];

    protected $hidden = ['password', 'remember_token', 'phone', 'address', 'insurance_reference', 'hourly_rate',
        'emergency_contact', 'identity_documents', 'osha_documents'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'insurance_reference' => 'encrypted',
            'emergency_contact' => 'encrypted:array',
            'hourly_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'identity_documents' => 'array',
            'osha_documents' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $panel->getId() === 'admin' && $this->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ]);
    }

    public static function operationalRoles(): array
    {
        return [LogisticsRole::Supervisor->value, LogisticsRole::Carpenter->value, LogisticsRole::Helper->value];
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $roles) => $roles->where('guard_name', 'web')->whereIn('name', self::operationalRoles()))
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereNotIn('name', self::operationalRoles()));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true)
            ->whereNull($this->getQualifiedDeletedAtColumn());
    }

    public function scopeWorkersVisibleTo(Builder $query, User $actor): Builder
    {
        $query->operational();

        if ($actor->can(LogisticsPermission::GlobalAccess->value)) {
            return $query;
        }

        if ($actor->can(LogisticsPermission::SuperviseSites->value)) {
            return $query->whereHas('jobSites', fn (Builder $sites) => $sites->visibleTo($actor));
        }

        return $query->whereRaw('1 = 0');
    }

    public function primaryJobSite(): BelongsTo
    {
        return $this->belongsTo(JobSite::class, 'primary_job_site_id');
    }

    public function jobSites(): BelongsToMany
    {
        return $this->belongsToMany(JobSite::class)->withTimestamps();
    }

    public function clockableJobSites(): BelongsToMany
    {
        return $this->jobSites()->whereIn('job_sites.status', ['planned', 'active']);
    }

    public function canResumeWorkToday(): bool
    {
        $today = now(config('app.business_timezone'))->startOfDay()->utc();

        return $this->hasAnyRole(self::operationalRoles()) && $this->attendances()
            ->withoutGlobalScope(LogisticsVisibilityScope::class)
            ->where('clocked_out_at', '>=', $today)->where('clocked_out_at', '<=', now())->exists();
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
