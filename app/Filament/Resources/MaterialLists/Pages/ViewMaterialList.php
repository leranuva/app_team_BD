<?php

namespace App\Filament\Resources\MaterialLists\Pages;

use App\Filament\Resources\MaterialLists\MaterialListResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMaterialList extends ViewRecord
{
    protected static string $resource = MaterialListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ...MaterialListResource::transitionActions(),
        ];
    }
}
