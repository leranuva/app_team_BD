<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobScope extends Model
{
    use HasLogisticsVisibility, SoftDeletes;

    protected $fillable = ['job_site_id', 'supervisor_id', 'name', 'description', 'status', 'progress'];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'approved_adjustment' => 'decimal:2',
            'approved_extra_days' => 'integer',
        ];
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
}
