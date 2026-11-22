<?php

namespace App\Filament\Resources\MaterialLists\Pages;

use App\Filament\Resources\MaterialLists\MaterialListResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaterialLists extends ListRecords
{
    protected static string $resource = MaterialListResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
