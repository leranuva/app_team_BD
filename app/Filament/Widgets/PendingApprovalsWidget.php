<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\PendingChangeOrders;
use App\Filament\Resources\MaterialLists\MaterialListResource;
use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ReportQueryService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class PendingApprovalsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 21;

    protected ?string $heading = 'Awaiting approval';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('viewAny', ChangeOrder::class);
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $reports = app(ReportQueryService::class);
        $orders = $user instanceof User ? $reports->pendingChangeOrders($user, [])->count() : 0;
        $lists = $user instanceof User ? $reports->pendingMaterialListCount($user) : 0;

        return [
            Stat::make('Pending change orders', $orders)->url(PendingChangeOrders::getUrl()),
            Stat::make('Pending lists', $lists)->url(MaterialListResource::getUrl('index')),
        ];
    }
}
