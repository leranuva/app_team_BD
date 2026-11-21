<?php

namespace App\Filament\Resources\JobScopes\Pages;

use App\Filament\Resources\JobScopes\JobScopeResource;
use Filament\Resources\Pages\ListRecords;

class ListJobScopes extends ListRecords
{
    protected static string $resource = JobScopeResource::class;
}
