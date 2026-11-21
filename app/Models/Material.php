<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Material extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['sku', 'name', 'unit', 'unit_cost', 'supplier', 'minimum_stock'];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'stock' => 'decimal:3',
            'minimum_stock' => 'decimal:3',
        ];
    }

    public function listItems(): HasMany
    {
        return $this->hasMany(MaterialListItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sku', 'name', 'unit', 'unit_cost', 'supplier', 'stock', 'minimum_stock'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
