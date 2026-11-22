<?php

namespace App\Filament\Widgets;

use App\Enums\MaterialListStatus;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MaterialListScopeWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    public $record = null;

    protected function getStats(): array
    {
        $scopeId = $this->record?->getKey();
        $lists = MaterialList::query()->where('job_scope_id', $scopeId);
        $pending = (clone $lists)->where('status', MaterialListStatus::Submitted)->count();
        $itemQuery = MaterialListItem::query()->whereHas('materialList', fn ($query) => $query->where('job_scope_id', $scopeId));
        $missingCost = (clone $itemQuery)->where('consumed_quantity', '>', 0)->whereNull('unit_cost_cents')->exists();
        $cents = (int) (clone $itemQuery)->whereNotNull('unit_cost_cents')->whereNotNull('consumed_quantity')
            ->selectRaw('coalesce(sum(consumed_quantity * unit_cost_cents), 0) as cents')->value('cents');
        $consumption = $missingCost || $cents === 0
            ? 'no recorded costs'
            : '$'.number_format($cents / 100, 2);

        return [
            Stat::make('Material lists', (clone $lists)->count()),
            Stat::make('Awaiting approval', $pending),
            Stat::make('Total usage', $consumption),
        ];
    }
}
