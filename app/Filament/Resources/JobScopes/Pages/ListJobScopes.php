<?php

namespace App\Filament\Resources\JobScopes\Pages;

use App\Filament\Resources\JobScopes\JobScopeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJobScopes extends ListRecords
{
    protected static string $resource = JobScopeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
