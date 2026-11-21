<?php

namespace App\Filament\Resources\JobSites\Pages;

use App\Filament\Resources\JobSites\JobSiteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewJobSite extends ViewRecord
{
    protected static string $resource = JobSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
