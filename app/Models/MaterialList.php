<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialList extends Model
{
    use HasLogisticsVisibility, LogsActivity, SoftDeletes;

    protected $fillable = ['job_site_id', 'job_scope_id', 'assigned_to', 'name', 'status'];

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

    public function items(): HasMany
    {
        return $this->hasMany(MaterialListItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['job_site_id', 'job_scope_id', 'assigned_to', 'name', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
