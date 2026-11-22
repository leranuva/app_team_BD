<?php

namespace App\Filament\Resources\Materials\Pages;

use App\Filament\Resources\Materials\MaterialResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMaterial extends EditRecord
{
    protected static string $resource = MaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), MaterialResource::deleteAction()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return MaterialResource::mapCatalogFields($data);
    }
}
