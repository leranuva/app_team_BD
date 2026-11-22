<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobScope extends Model
{
    use HasLogisticsVisibility, SoftDeletes;

    protected $fillable = [
        'job_site_id', 'supervisor_id', 'name', 'description', 'status', 'progress',
        'start_date', 'estimated_end_date', 'notes',
        'approved_change_orders_cents', 'approved_time_impact_days',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'approved_adjustment' => 'decimal:2',
            'approved_extra_days' => 'integer',
            'start_date' => 'date',
            'estimated_end_date' => 'date',
            'approved_change_orders_cents' => 'integer',
            'approved_time_impact_days' => 'integer',
        ];
    }

    public function scopeReadableBy(Builder $query, User $user): Builder
    {
        return $query->withoutGlobalScope(LogisticsVisibilityScope::class)
            ->whereHas('jobSite', fn (Builder $sites) => $sites->readableBy($user));
    }

    public function scopeWithOperationalCounts(Builder $query): Builder
    {
        return $query->withCount([
            'photos as photos_count' => fn (Builder $photos) => $photos->active(),
            'shifts as shifts_count' => fn (Builder $shifts) => $shifts->whereIn('status', ['programmed', 'confirmed']),
        ]);
    }

    public function jobSite(): BelongsTo
    {
        return $this->belongsTo(JobSite::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class);
    }

    public function materialLists(): HasMany
    {
        return $this->hasMany(MaterialList::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ScopePhoto::class)->latest('created_at')->orderByDesc('scope_photos.id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
