<?php

namespace App\Filament\Resources\JobScopes;

use App\Enums\LogisticsRole;
use App\Filament\Resources\ChangeOrders\RelationManagers\ChangeOrdersRelationManager;
use App\Filament\Resources\JobScopes\Pages\CreateJobScope;
use App\Filament\Resources\JobScopes\Pages\EditJobScope;
use App\Filament\Resources\JobScopes\Pages\ListJobScopes;
use App\Filament\Resources\JobScopes\Pages\ViewJobScope;
use App\Filament\Resources\JobScopes\RelationManagers\ScopePhotosRelationManager;
use App\Filament\Resources\JobScopes\RelationManagers\ShiftsRelationManager;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class JobScopeResource extends Resource
{
    protected static ?string $model = JobScope::class;

    protected static ?string $modelLabel = 'alcance';

    protected static ?string $pluralModelLabel = 'alcances';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public const STATUSES = [
        'pending' => 'Pendiente',
        'active' => 'Activo',
        'in_progress' => 'En progreso',
        'completed' => 'Completado',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Auth::user())->withOperationalCounts();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('job_site_id')->label('Obra')->required()->searchable()->live()
                ->options(fn (): array => JobSite::visibleTo(Auth::user())->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(fn (Set $set) => $set('supervisor_id', null))
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $site = JobSite::query()->find($value);
                    if ($site === null || ! Auth::user()->can('create', [JobScope::class, $site])) {
                        $fail('No puedes crear alcances en esta obra.');
                    }
                }),
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            Textarea::make('description')->label('Descripción')->maxLength(2000)->columnSpanFull(),
            Select::make('status')->label('Estado')->required()->options(self::STATUSES)->default('pending')->live(),
            TextInput::make('progress')->label('Avance (%)')->required()->numeric()->integer()->minValue(0)->maxValue(100)->default(0)
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if ((int) $value === 100 && $get('status') !== 'completed') {
                        $fail('El avance del 100 % solo corresponde a un alcance completado.');
                    }
                }),
            Select::make('supervisor_id')->label('Responsable')->searchable()
                ->options(function (Get $get): array {
                    if (! $get('job_site_id')) {
                        return [];
                    }

                    return User::query()->role(LogisticsRole::Supervisor->value)
                        ->whereHas('jobSites', fn (Builder $sites) => $sites->whereKey($get('job_site_id')))
                        ->orderBy('name')->pluck('name', 'id')->all();
                })
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $belongs = User::query()->role(LogisticsRole::Supervisor->value)
                        ->whereKey($value)
                        ->whereHas('jobSites', fn (Builder $sites) => $sites->whereKey($get('job_site_id')))
                        ->exists();

                    if (! $belongs) {
                        $fail('El responsable debe ser un supervisor de la obra.');
                    }
                }),
            DatePicker::make('start_date')->label('Inicio')->native(false),
            DatePicker::make('estimated_end_date')->label('Fin estimado')->native(false)->afterOrEqual('start_date'),
            Textarea::make('notes')->label('Notas')->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('jobSite.name')->label('Obra'),
            TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            TextEntry::make('progress')->label('Avance')->suffix('%'),
            TextEntry::make('supervisor.name')->label('Responsable')->placeholder('Sin asignar'),
            TextEntry::make('start_date')->label('Inicio')->date('d/m/Y')->placeholder('Sin fecha'),
            TextEntry::make('estimated_end_date')->label('Fin estimado')->date('d/m/Y')->placeholder('Sin fecha'),
            TextEntry::make('description')->label('Descripción')->placeholder('Sin descripción')->columnSpanFull(),
            TextEntry::make('notes')->label('Notas')->placeholder('Sin notas')->columnSpanFull(),
            TextEntry::make('approved_change_orders_cents')->label('Change Orders aprobadas')
                ->formatStateUsing(fn (JobScope $record): string => '$'.number_format($record->approved_change_orders_cents / 100, 2).' · '.$record->approved_time_impact_days.' días')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Alcance')->searchable()->sortable(),
            TextColumn::make('jobSite.name')->label('Obra')->searchable(),
            TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            ViewColumn::make('progress')->label('Avance')->view('filament.columns.progress'),
            TextColumn::make('supervisor.name')->label('Responsable')->placeholder('Sin asignar'),
            TextColumn::make('photos_count')->label('Fotos activas')->numeric()->sortable(),
            TextColumn::make('shifts_count')->label('Turnos activos')->numeric(),
        ])->filters([
            SelectFilter::make('job_site_id')->label('Obra')
                ->options(fn (): array => JobSite::visibleTo(Auth::user())->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('status')->label('Estado')->options(self::STATUSES),
            SelectFilter::make('supervisor_id')->label('Responsable')->relationship('supervisor', 'name')->searchable(),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            Action::make('changeStatus')->label('Cambiar estado')
                ->visible(fn (JobScope $record): bool => Auth::user()->can('update', $record))
                ->fillForm(fn (JobScope $record): array => ['status' => $record->status])
                ->schema([
                    Select::make('status')->label('Estado')->options(self::STATUSES)->required(),
                ])
                ->modalHeading('Cambiar estado')
                ->modalDescription('Completar el alcance cierra su avance operativo. Confirma el estado antes de guardar.')
                ->action(function (JobScope $record, array $data): void {
                    if ($data['status'] !== 'completed' && (int) $record->progress === 100) {
                        $record->progress = 99;
                    }
                    $record->status = $data['status'];
                    $record->save();
                }),
            Action::make('duplicate')->label('Duplicar')
                ->visible(fn (JobScope $record): bool => Auth::user()->can('create', [JobScope::class, $record->jobSite]))
                ->requiresConfirmation()
                ->modalDescription('Se crea otro alcance en la misma obra, pendiente y sin avance. Las fotos y los turnos no se copian.')
                ->action(function (JobScope $record): void {
                    $copy = $record->replicate(['photos_count', 'shifts_count']);
                    $copy->name = str($record->name.' (copia)')->limit(255, '')->toString();
                    $copy->status = 'pending';
                    $copy->progress = 0;
                    $copy->save();
                }),
            Action::make('openGallery')->label('Ver fotos')
                ->url(fn (JobScope $record): string => route('job-scopes.show', $record))
                ->openUrlInNewTab(),
        ]);
    }

    public static function getRelations(): array
    {
        return [ScopePhotosRelationManager::class, ShiftsRelationManager::class, ChangeOrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobScopes::route('/'),
            'create' => CreateJobScope::route('/create'),
            'view' => ViewJobScope::route('/{record}'),
            'edit' => EditJobScope::route('/{record}/edit'),
        ];
    }
}
