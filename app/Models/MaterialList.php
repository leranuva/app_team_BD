<?php

namespace App\Models;

use App\Enums\LogisticsPermission;
use App\Enums\MaterialListStatus;
use App\Models\Concerns\HasLogisticsVisibility;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialList extends Model
{
    use HasFactory, HasLogisticsVisibility, LogsActivity, SoftDeletes;

    protected $fillable = [
        'job_site_id', 'job_scope_id', 'assigned_to', 'name', 'title', 'code', 'notes', 'status',
        'requested_by', 'approved_by', 'ordered_at', 'delivered_at', 'consumed_at', 'cancelled_reason',
    ];

    private ?User $actor = null;

    private ?string $activityNote = null;

    protected function casts(): array
    {
        return [
            'status' => MaterialListStatus::class,
            'ordered_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MaterialList $list): void {
            if (blank($list->title) && filled($list->name)) {
                $list->title = $list->name;
            }
            if (blank($list->name) && filled($list->title)) {
                $list->name = $list->title;
            }
        });
    }

    public function jobSite(): BelongsTo
    {
        return $this->belongsTo(JobSite::class);
    }

    public function jobScope(): BelongsTo
    {
        return $this->belongsTo(JobScope::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialListItem::class);
    }

    public function scopeForScope(Builder $query, int $id): Builder
    {
        return $query->where('job_scope_id', $id);
    }

    public function scopeForSite(Builder $query, int $id): Builder
    {
        return $query->where('job_site_id', $id);
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', MaterialListStatus::Submitted);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        $query->withoutGlobalScope(LogisticsVisibilityScope::class);

        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can(LogisticsPermission::GlobalAccess->value)) {
            return $query;
        }

        if ($user->can(LogisticsPermission::SuperviseSites->value)) {
            return $query->whereHas('jobSite', fn (Builder $sites) => $sites
                ->withoutGlobalScope(LogisticsVisibilityScope::class)
                ->whereHas('users', fn (Builder $members) => $members->whereKey($user->getKey())));
        }

        return $query->whereRaw('1 = 0');
    }

    public function isEditable(): bool
    {
        return $this->status === MaterialListStatus::Draft;
    }

    public function canBeSubmitted(): bool
    {
        return $this->isEditable() && $this->items()->exists();
    }

    public function allItemsDelivered(): bool
    {
        $items = $this->items()->get();

        return $items->isNotEmpty() && $items->every(fn (MaterialListItem $item): bool => $item->isFullyDelivered());
    }

    public function allItemsConsumed(): bool
    {
        $items = $this->items()->get();

        return $items->isNotEmpty() && $items->every(fn (MaterialListItem $item): bool => $item->isFullyConsumed());
    }

    public function totalRequestedValueCents(): ?int
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        if ($items->isEmpty()) {
            return 0;
        }

        $total = 0;
        foreach ($items as $item) {
            if ($item->unit_cost_cents === null) {
                return null;
            }
            $total += (int) round(((float) $item->requested_quantity) * $item->unit_cost_cents);
        }

        return $total;
    }

    public function requesterId(): ?int
    {
        return $this->requested_by === null ? null : (int) $this->requested_by;
    }

    public function actorBelongsToSite(User $actor): bool
    {
        $site = $this->jobSite;
        if ($site === null) {
            return false;
        }

        if ($site->relationLoaded('users')) {
            return $site->users->contains(fn (User $user): bool => $user->is($actor));
        }

        return $site->users()->whereKey($actor->id)->exists();
    }

    public function recordedBy(User $actor, ?string $note = null): static
    {
        $this->actor = $actor;
        $this->activityNote = $note;

        return $this;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('material_lists')
            ->logOnly(['status', 'approved_by', 'cancelled_reason', 'delivered_at', 'consumed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity): void
    {
        if ($this->actor !== null) {
            $activity->causer()->associate($this->actor);
        }
        if ($this->activityNote !== null) {
            $activity->properties = $activity->properties->put('note', $this->activityNote);
        }
    }
}
