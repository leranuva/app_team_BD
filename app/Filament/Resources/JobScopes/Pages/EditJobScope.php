<?php

namespace App\Filament\Resources\JobScopes\Pages;

use App\Filament\Resources\JobScopes\JobScopeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJobScope extends EditRecord
{
    protected static string $resource = JobScopeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
