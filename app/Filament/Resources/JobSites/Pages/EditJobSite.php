<?php

namespace App\Filament\Resources\JobSites\Pages;

use App\Filament\Resources\JobSites\JobSiteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJobSite extends EditRecord
{
    protected static string $resource = JobSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
