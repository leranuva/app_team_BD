<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobSite extends Model
{
    use HasFactory, HasLogisticsVisibility, SoftDeletes;

    protected $fillable = ['name', 'address', 'client', 'latitude', 'longitude', 'geofence_meters', 'status', 'base_budget'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'base_budget' => 'decimal:2',
            'approved_adjustment' => 'decimal:2',
            'geofence_meters' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(JobScope::class);
    }

    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class);
    }

    public function materialLists(): HasMany
    {
        return $this->hasMany(MaterialList::class);
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
