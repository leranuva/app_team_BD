<?php

namespace App\Models;

use App\Models\Concerns\HasLogisticsVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialListItem extends Model
{
    use HasFactory, HasLogisticsVisibility, LogsActivity;

    protected $fillable = [
        'material_list_id', 'material_id', 'budgeted_quantity', 'used_quantity',
        'requested_quantity', 'approved_quantity', 'delivered_quantity', 'consumed_quantity',
        'unit_cost_cents', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'budgeted_quantity' => 'decimal:3',
            'used_quantity' => 'decimal:3',
            'requested_quantity' => 'decimal:3',
            'approved_quantity' => 'decimal:3',
            'delivered_quantity' => 'decimal:3',
            'consumed_quantity' => 'decimal:3',
            'unit_cost_cents' => 'integer',
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

    public function pendingDelivery(): string
    {
        $target = $this->approved_quantity ?? $this->requested_quantity ?? '0';
        $delivered = $this->delivered_quantity ?? '0';

        return bccomp($this->decimal($target), $this->decimal($delivered), 3) > 0
            ? bcsub($this->decimal($target), $this->decimal($delivered), 3)
            : '0.000';
    }

    public function pendingConsumption(): string
    {
        $delivered = $this->delivered_quantity ?? '0';
        $consumed = $this->consumed_quantity ?? '0';

        return bccomp($this->decimal($delivered), $this->decimal($consumed), 3) > 0
            ? bcsub($this->decimal($delivered), $this->decimal($consumed), 3)
            : '0.000';
    }

    public function isFullyDelivered(): bool
    {
        if ($this->delivered_quantity === null) {
            return false;
        }

        $target = $this->approved_quantity ?? $this->requested_quantity ?? '0';

        return bccomp($this->decimal($this->delivered_quantity), $this->decimal($target), 3) >= 0;
    }

    public function isFullyConsumed(): bool
    {
        if ($this->delivered_quantity === null || $this->consumed_quantity === null) {
            return false;
        }

        return bccomp($this->decimal($this->consumed_quantity), $this->decimal($this->delivered_quantity), 3) >= 0;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('material_list_items')
            ->logOnly(['requested_quantity', 'approved_quantity', 'delivered_quantity', 'consumed_quantity', 'unit_cost_cents', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
