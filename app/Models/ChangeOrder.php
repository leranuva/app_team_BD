<?php

namespace App\Models;

use App\Enums\ChangeOrderStatus;
use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ChangeOrder extends Model
{
    use HasFactory, HasLogisticsVisibility, LogsActivity, SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'amount_cents' => 0,
        'time_impact_days' => 0,
        'currency' => 'USD',
    ];

    protected $fillable = [
        'job_site_id', 'job_scope_id', 'code', 'title', 'description', 'status',
        'created_by', 'requested_by', 'reviewed_by', 'approved_by',
        'amount_cents', 'currency', 'time_impact_days', 'schedule_impact_note',
        'submitted_at', 'reviewed_at', 'approved_at', 'rejected_at',
        'rejection_reason', 'cancelled_reason', 'applied_to_scope_at',
    ];

    private ?User $actor = null;

    private ?string $activityNote = null;

    protected function casts(): array
    {
        return [
            'status' => ChangeOrderStatus::class,
            'amount_cents' => 'integer',
            'time_impact_days' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'applied_to_scope_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ChangeOrder $order): void {
            $order->requested_by ??= $order->created_by;
            $order->created_by ??= $order->requested_by;
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChangeOrderAttachment::class);
    }

    public function scopeForSite(Builder $query, int $id): Builder
    {
        return $query->where('job_site_id', $id);
    }

    public function scopeForScope(Builder $query, int $id): Builder
    {
        return $query->where('job_scope_id', $id);
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [ChangeOrderStatus::Submitted, ChangeOrderStatus::UnderReview]);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ChangeOrderStatus::Approved);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->visibleTo($user);
    }

    public function requesterId(): int
    {
        return (int) ($this->requested_by ?? $this->created_by);
    }

    public function isEditable(): bool
    {
        return $this->status === ChangeOrderStatus::Draft;
    }

    public function canBeSubmitted(): bool
    {
        return $this->status === ChangeOrderStatus::Draft;
    }

    public function canBeReviewed(): bool
    {
        return $this->status === ChangeOrderStatus::Submitted;
    }

    public function canBeApproved(): bool
    {
        return $this->status === ChangeOrderStatus::UnderReview;
    }

    public function amountInDollars(): float
    {
        return $this->amount_cents / 100;
    }

    public function isFinancialImpactPositive(): bool
    {
        return $this->amount_cents > 0;
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
        return LogOptions::defaults()->useLogName('change_orders')
            ->logOnly(['status', 'amount_cents', 'time_impact_days', 'reviewed_by', 'approved_by', 'rejection_reason'])
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
