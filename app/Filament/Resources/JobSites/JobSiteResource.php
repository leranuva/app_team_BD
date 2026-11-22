<?php

namespace App\Filament\Resources\JobSites;

use App\Enums\LogisticsRole;
use App\Filament\Resources\ChangeOrders\RelationManagers\ChangeOrdersRelationManager;
use App\Filament\Resources\JobSites\Pages\CreateJobSite;
use App\Filament\Resources\JobSites\Pages\EditJobSite;
use App\Filament\Resources\JobSites\Pages\ListJobSites;
use App\Filament\Resources\JobSites\Pages\ViewJobSite;
use App\Filament\Resources\JobSites\RelationManagers\ScopesRelationManager;
use App\Models\JobSite;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class JobSiteResource extends Resource
{
    protected static ?string $model = JobSite::class;

    protected static ?string $modelLabel = 'obra';

    protected static ?string $pluralModelLabel = 'obras';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    public const STATUSES = [
        'planned' => 'Planificada',
        'active' => 'Activa',
        'on_hold' => 'En pausa',
        'completed' => 'Completada',
        'cancelled' => 'Cancelada',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Auth::user())->with('supervisors')->withOperationalCounts();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            TextInput::make('client')->label('Cliente')->required()->maxLength(255),
            TextInput::make('address')->label('Dirección')->required()->maxLength(255)
                ->helperText('La geocodificación es manual: escribe la latitud y la longitud.')
                ->columnSpanFull(),
            TextInput::make('latitude')->label('Latitud')->required()->numeric()->minValue(-90)->maxValue(90)->step(0.000001),
            TextInput::make('longitude')->label('Longitud')->required()->numeric()->minValue(-180)->maxValue(180)->step(0.000001),
            TextInput::make('geofence_meters')->label('Radio de fichaje (m)')->required()->integer()->minValue(50)->maxValue(500)->default(150),
            Select::make('status')->label('Estado')->required()->options(self::STATUSES)->default('planned'),
            DatePicker::make('start_date')->label('Inicio')->native(false),
            DatePicker::make('estimated_end_date')->label('Fin estimado')->native(false)->afterOrEqual('start_date'),
            TextInput::make('base_budget')->label('Presupuesto base (USD)')->required()->numeric()->minValue(0)
                ->maxValue(999999999999.99)->step(0.01)->default(0),
            Textarea::make('notes')->label('Notas')->maxLength(2000)->columnSpanFull(),
            Select::make('users')->label('Equipo asignado')->multiple()->relationship('users', 'name')
                ->searchable()->nestedRecursiveRules(['integer', 'exists:users,id'])
                ->visible(fn (string $operation): bool => $operation !== 'view')->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Nombre'),
            TextEntry::make('client')->label('Cliente'),
            TextEntry::make('address')->label('Dirección')->columnSpanFull(),
            TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            TextEntry::make('geofence_meters')->label('Radio de fichaje')->suffix(' m'),
            TextEntry::make('start_date')->label('Inicio')->date('d/m/Y')->placeholder('Sin fecha'),
            TextEntry::make('estimated_end_date')->label('Fin estimado')->date('d/m/Y')->placeholder('Sin fecha'),
            TextEntry::make('supervisors.name')->label('Supervisores')->badge()->placeholder('Sin supervisor'),
            TextEntry::make('notes')->label('Notas')->placeholder('Sin notas')->columnSpanFull(),
            TextEntry::make('approved_change_orders_cents')->label('Change Orders aprobadas')
                ->formatStateUsing(fn (JobSite $record): string => '$'.number_format($record->approved_change_orders_cents / 100, 2).' · '.$record->approved_time_impact_days.' días')
                ->columnSpanFull(),
            ViewEntry::make('map')->label('Mapa')->view('filament.job-sites.map', fn (JobSite $record): array => [
                'lat' => $record->latitude,
                'lng' => $record->longitude,
                'radius' => $record->geofence_meters,
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Obra')->searchable()->sortable(),
                TextColumn::make('client')->label('Cliente')->searchable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                TextColumn::make('supervisors.name')->label('Supervisores')->badge()->placeholder('Sin supervisor'),
                TextColumn::make('active_scopes_count')->label('Alcances activos')->numeric(),
                TextColumn::make('active_photos_count')->label('Fotos activas')->numeric(),
                TextColumn::make('start_date')->label('Inicio')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('estimated_end_date')->label('Fin estimado')->date('d/m/Y')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(self::STATUSES),
                SelectFilter::make('users')->label('Supervisor')->relationship(
                    'users',
                    'name',
                    fn (Builder $query) => $query->role(LogisticsRole::Supervisor->value),
                )->searchable(),
                Filter::make('schedule')->label('Fechas')->schema([
                    DatePicker::make('from')->label('Desde'),
                    DatePicker::make('until')->label('Hasta'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $sites, string $date) => $sites->whereDate('start_date', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $sites, string $date) => $sites->whereDate('start_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('changeStatus')->label('Cambiar estado')
                    ->visible(fn (JobSite $record): bool => Auth::user()->can('update', $record))
                    ->fillForm(fn (JobSite $record): array => ['status' => $record->status])
                    ->schema([
                        Select::make('status')->label('Estado')->options(self::STATUSES)->required(),
                    ])
                    ->modalHeading('Cambiar estado')
                    ->modalDescription('Completar o cancelar cierra la operación de la obra. Confirma el estado antes de guardar.')
                    ->action(function (JobSite $record, array $data): void {
                        $record->update(['status' => $data['status']]);
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [ScopesRelationManager::class, ChangeOrdersRelationManager::class];
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
