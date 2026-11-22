<?php

namespace App\Filament\Resources\ChangeOrders\RelationManagers;

use App\Filament\Resources\ChangeOrders\ChangeOrderResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChangeOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'changeOrders';

    protected static ?string $title = 'Órdenes de cambio';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Código')->placeholder('Sin código'),
            TextColumn::make('title')->label('Título'),
            TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state) => $state->label()),
            TextColumn::make('amount_cents')->label('Monto')->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
            TextColumn::make('time_impact_days')->label('Días'),
        ])->recordActions([
            ViewAction::make()->url(fn ($record): string => ChangeOrderResource::getUrl('view', ['record' => $record])),
        ]);
    }
}
