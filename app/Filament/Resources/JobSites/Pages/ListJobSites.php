<?php

namespace App\Filament\Resources\JobSites\Pages;

use App\Filament\Resources\JobSites\JobSiteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJobSites extends ListRecords
{
    protected static string $resource = JobSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
