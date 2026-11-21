<?php

namespace App\Filament\Resources\JobSites;

use App\Filament\Resources\JobSites\Pages\CreateJobSite;
use App\Filament\Resources\JobSites\Pages\EditJobSite;
use App\Filament\Resources\JobSites\Pages\ListJobSites;
use App\Filament\Resources\JobSites\Pages\ViewJobSite;
use App\Models\JobSite;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class JobSiteResource extends Resource
{
    protected static ?string $model = JobSite::class;

    protected static ?string $modelLabel = 'obra';

    protected static ?string $pluralModelLabel = 'obras';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    private const STATUSES = [
        'planned' => 'Planificada',
        'active' => 'Activa',
        'on_hold' => 'En pausa',
        'completed' => 'Completada',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Auth::user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            TextInput::make('client')->label('Cliente')->required()->maxLength(255),
            TextInput::make('address')->label('Dirección')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('latitude')->label('Latitud')->required()->numeric()->minValue(-90)->maxValue(90)->step(0.000001),
            TextInput::make('longitude')->label('Longitud')->required()->numeric()->minValue(-180)->maxValue(180)->step(0.000001),
            TextInput::make('geofence_meters')->label('Radio de fichaje (m)')->required()->integer()->minValue(10)->maxValue(5000)->default(150),
            Select::make('status')->label('Estado')->required()->options(self::STATUSES)->default('planned'),
            TextInput::make('base_budget')->label('Presupuesto base (USD)')->required()->numeric()->minValue(0)
                ->maxValue(999999999999.99)->step(0.01)->default(0),
            Select::make('users')->label('Equipo asignado')->multiple()->relationship('users', 'name')
                ->searchable()->nestedRecursiveRules(['integer', 'exists:users,id'])
                ->visible(fn (string $operation): bool => $operation !== 'view')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Obra')->searchable()->sortable(),
                TextColumn::make('client')->label('Cliente')->searchable(),
                TextColumn::make('address')->label('Dirección')->searchable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                TextColumn::make('base_budget')->label('Presupuesto base')->money('USD')->sortable(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobSites::route('/'),
            'create' => CreateJobSite::route('/create'),
            'view' => ViewJobSite::route('/{record}'),
            'edit' => EditJobSite::route('/{record}/edit'),
        ];
    }
}
