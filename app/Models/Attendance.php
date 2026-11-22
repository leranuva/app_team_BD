<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Attendance extends Model
{
    use HasFactory, HasLogisticsVisibility, LogsActivity;

    protected $attributes = ['geofence_flagged' => false];

    private ?User $recordedBy = null;

    private ?string $correctionReason = null;

    protected $fillable = ['job_site_id', 'user_id', 'shift_id', 'clocked_in_at', 'clocked_out_at', 'in_latitude', 'in_longitude', 'out_latitude', 'out_longitude'];

    protected function casts(): array
    {
        return [
            'clocked_in_at' => 'immutable_datetime',
            'clocked_out_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'in_latitude' => 'decimal:6',
            'in_longitude' => 'decimal:6',
            'out_latitude' => 'decimal:6',
            'out_longitude' => 'decimal:6',
            'regular_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'double_time_minutes' => 'integer',
            'duration_minutes' => 'integer',
            'geofence_flagged' => 'boolean',
            'in_accuracy_meters' => 'decimal:2',
            'out_accuracy_meters' => 'decimal:2',
        ];
    }

    public function jobSite(): BelongsTo
    {
        return $this->belongsTo(JobSite::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * Derived from the punch and the correction. There is no status column.
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->corrected_by !== null) {
                return 'corrected';
            }

            return $this->clocked_out_at === null ? 'open' : 'closed';
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('attendances')
            ->logOnly(['user_id', 'shift_id', 'job_site_id', 'clocked_in_at', 'clocked_out_at',
                'geofence_flagged', 'duration_minutes', 'regular_minutes', 'overtime_minutes', 'double_time_minutes',
                'corrected_by', 'correction_reason'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event): string => $this->correctionReason !== null ? 'attendance.corrected' : $event);
    }

    public function recordedBy(User $user): static
    {
        $this->recordedBy = $user;

        return $this;
    }

    public function correctedBy(User $user, string $reason): static
    {
        $this->recordedBy = $user;
        $this->correctionReason = $reason;

        return $this;
    }

    public function tapActivity(Activity $activity): void
    {
        if ($this->recordedBy !== null) {
            $activity->causer()->associate($this->recordedBy);
        }
        if ($this->correctionReason !== null) {
            $activity->properties = $activity->properties->put('reason', $this->correctionReason);
        }
    }
}
