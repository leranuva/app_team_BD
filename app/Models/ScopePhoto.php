<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Observers\ScopePhotoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(ScopePhotoObserver::class)]
class ScopePhoto extends Model
{
    use HasFactory, HasLogisticsVisibility, LogsActivity;

    protected $fillable = [
        'job_scope_id', 'job_site_id', 'uploaded_by', 'path', 'original_name', 'mime_type',
        'size_bytes', 'caption', 'lat', 'lng', 'taken_at',
    ];

    protected $hidden = ['path'];

    private ?User $recordedBy = null;

    private ?string $archiveReason = null;

    protected static function booted(): void
    {
        static::creating(function (ScopePhoto $photo): void {
            $siteId = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)
                ->whereKey($photo->job_scope_id)->value('job_site_id');

            if ($siteId === null || ($photo->job_site_id !== null && (int) $photo->job_site_id !== (int) $siteId)) {
                throw ValidationException::withMessages(['job_site_id' => 'La obra debe coincidir con la del alcance.']);
            }

            $photo->job_site_id = $siteId;
        });

        static::deleting(function (): never {
            throw ValidationException::withMessages(['photo' => 'Archiva la foto para conservar su historial.']);
        });
    }

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'size_bytes' => 'integer',
            'taken_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function jobScope(): BelongsTo
    {
        return $this->belongsTo(JobScope::class);
    }

    public function jobSite(): BelongsTo
    {
        return $this->belongsTo(JobSite::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeForScope(Builder $query, int $id): Builder
    {
        return $query->where('job_scope_id', $id);
    }

    public function scopeForSite(Builder $query, int $id): Builder
    {
        return $query->where('job_site_id', $id);
    }

    public function temporaryUrl(int $minutes = 15): ?string
    {
        if ($this->archived_at !== null || $minutes < 1) {
            return null;
        }

        try {
            $disk = Storage::disk('s3');

            return $disk->providesTemporaryUrls() && $disk->exists($this->path)
                ? $disk->temporaryUrl($this->path, now()->addMinutes($minutes))
                : null;
        } catch (FilesystemException|RuntimeException $exception) {
            report($exception);

            return null;
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('scope_photos')
            ->logOnly(['caption', 'archived_at'])->logOnlyDirty()->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => $event === 'updated'
                && $this->isDirty('archived_at')
                ? ($this->archived_at !== null ? 'scope_photo.archived' : 'scope_photo.restored')
                : $event);
    }

    public function recordedBy(User $actor, ?string $reason = null): static
    {
        $this->recordedBy = $actor;
        $this->archiveReason = $reason;

        return $this;
    }

    public function tapActivity(Activity $activity): void
    {
        if ($this->recordedBy !== null) {
            $activity->causer()->associate($this->recordedBy);
        }
        if ($activity->description === 'scope_photo.archived') {
            $activity->properties = $activity->properties->put('reason', $this->archiveReason);
        }
    }
}
