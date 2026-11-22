<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LowConsumptionMaterials;
use App\Models\MaterialList;
use App\Models\User;
use App\Services\ReportQueryService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class LowConsumptionWidget extends Widget
{
    protected static ?int $sort = 22;

    protected string $view = 'filament.widgets.low-consumption';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('viewAny', MaterialList::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();

        return [
            'rows' => $user instanceof User ? app(ReportQueryService::class)->lowestConsumption($user) : collect(),
            'url' => LowConsumptionMaterials::getUrl(),
        ];
    }
}
