<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ChangeOrder extends Model
{
    use HasLogisticsVisibility, LogsActivity, SoftDeletes;

    protected $fillable = ['job_site_id', 'job_scope_id', 'created_by', 'description', 'additional_cost', 'extra_days', 'schedule_impact', 'attachments'];

    protected function casts(): array
    {
        return [
            'additional_cost' => 'decimal:2',
            'extra_days' => 'integer',
            'attachments' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['job_site_id', 'job_scope_id', 'created_by', 'reviewed_by', 'approved_by', 'description', 'additional_cost', 'extra_days', 'schedule_impact', 'attachments', 'status', 'reviewed_at', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
