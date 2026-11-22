<?php

namespace App\Filament\Resources\JobSites\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScopesRelationManager extends RelationManager
{
    protected static string $relationship = 'scopes';

    protected static ?string $title = 'Scopes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Scope'),
            TextColumn::make('status')->label('Status')->badge(),
            TextColumn::make('progress')->label('Progress')->suffix('%'),
        ]);
    }
}
