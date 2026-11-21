<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialListItem extends Model
{
    use HasLogisticsVisibility, LogsActivity;

    protected $fillable = ['material_list_id', 'material_id', 'budgeted_quantity', 'used_quantity'];

    protected function casts(): array
    {
        return [
            'budgeted_quantity' => 'decimal:3',
            'used_quantity' => 'decimal:3',
        ];
    }

    public function materialList(): BelongsTo
    {
        return $this->belongsTo(MaterialList::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['material_list_id', 'material_id', 'budgeted_quantity', 'used_quantity'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
