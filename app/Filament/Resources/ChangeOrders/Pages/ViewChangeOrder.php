<?php

namespace App\Filament\Resources\ChangeOrders\Pages;

use App\Filament\Resources\ChangeOrders\ChangeOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewChangeOrder extends ViewRecord
{
    protected static string $resource = ChangeOrderResource::class;

    protected function getHeaderActions(): array
    {
        return ChangeOrderResource::transitionActions();
    }
}
