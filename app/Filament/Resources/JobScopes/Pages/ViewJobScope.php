<?php

namespace App\Filament\Resources\JobScopes\Pages;

use App\Filament\Resources\JobScopes\JobScopeResource;
use App\Filament\Widgets\MaterialListScopeWidget;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewJobScope extends ViewRecord
{
    protected static string $resource = JobScopeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gallery')->label('View photos')
                ->url(fn (): string => route('job-scopes.show', $this->getRecord())),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [MaterialListScopeWidget::class];
    }
}
