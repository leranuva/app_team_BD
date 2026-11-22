<?php

namespace App\Filament\Resources\JobScopes\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShiftsRelationManager extends RelationManager
{
    protected static string $relationship = 'shifts';

    protected static ?string $title = 'Turnos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('worker.name')->label('Trabajador'),
            TextColumn::make('starts_at')->label('Fecha')
                ->formatStateUsing(fn ($state): string => $state->timezone(config('app.business_timezone'))->format('d/m/Y H:i')),
            TextColumn::make('status')->label('Estado')->badge()
                ->formatStateUsing(fn ($state): string => $state->label()),
        ])->defaultSort('starts_at');
    }
}
