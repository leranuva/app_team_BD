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
use App\Models\User;
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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class JobSiteResource extends Resource
{
    protected static ?string $model = JobSite::class;

    protected static ?string $modelLabel = 'job site';

    protected static ?string $pluralModelLabel = 'job sites';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    public const STATUSES = [
        'planned' => 'Planned',
        'active' => 'Active',
        'on_hold' => 'On hold',
        'completed' => 'Completed',
        'cancelled' => 'Canceled',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Auth::user())->with('supervisors')->withOperationalCounts();
    }

    public static function form(Schema $schema): Schema
    {
        $supervisorIds = fn (): Builder => User::query()->role(LogisticsRole::Supervisor->value, 'web')->select('users.id');

        return $schema->components([
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('client')->label('Client')->required()->maxLength(255),
            TextInput::make('address')->label('Address')->required()->maxLength(255)
                ->view('filament.job-sites.address-input')
                ->helperText('Choose a New York City suggestion to fill in the coordinates, or enter them manually.')
                ->columnSpanFull(),
            TextInput::make('latitude')->label('Latitude')->required()->numeric()->minValue(-90)->maxValue(90)->step(0.000001),
            TextInput::make('longitude')->label('Longitude')->required()->numeric()->minValue(-180)->maxValue(180)->step(0.000001),
            TextInput::make('geofence_meters')->label('Time clock radius (m)')->required()->integer()->minValue(50)->maxValue(500)->default(150),
            Select::make('status')->label('Status')->required()->options(self::STATUSES)->default('planned'),
            DatePicker::make('start_date')->label('Start')->native(false),
            DatePicker::make('estimated_end_date')->label('Estimated end')->native(false)->afterOrEqual('start_date'),
            TextInput::make('base_budget')->label('Base budget (USD)')->required()->numeric()->minValue(0)
                ->maxValue(999999999999.99)->step(0.01)->default(0),
            Textarea::make('notes')->label('Notes')->maxLength(2000)->columnSpanFull(),
            Select::make('supervisors')->label('Site supervisors')->multiple()->relationship('supervisors', 'name')
                ->searchable()->preload()
                ->nestedRecursiveRules(['integer', Rule::exists('users', 'id')->where(fn (QueryBuilder $query) => $query->whereIn('users.id', $supervisorIds()))])
                ->visible(fn (string $operation): bool => $operation !== 'view')->columnSpanFull(),
            Select::make('users')->label('Assigned team')->multiple()
                ->relationship('users', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereNotIn('users.id', $supervisorIds()))
                ->searchable()->preload()
                ->nestedRecursiveRules(['integer', Rule::exists('users', 'id')->where(fn (QueryBuilder $query) => $query->whereNotIn('users.id', $supervisorIds()))])
                ->visible(fn (string $operation): bool => $operation !== 'view')->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Name'),
            TextEntry::make('client')->label('Client'),
            TextEntry::make('address')->label('Address')->columnSpanFull(),
            TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            TextEntry::make('geofence_meters')->label('Time clock radius')->suffix(' m'),
            TextEntry::make('start_date')->label('Start')->date('m/d/Y')->placeholder('No date'),
            TextEntry::make('estimated_end_date')->label('Estimated end')->date('m/d/Y')->placeholder('No date'),
            TextEntry::make('supervisors.name')->label('Supervisors')->badge()->placeholder('No supervisor'),
            TextEntry::make('notes')->label('Notes')->placeholder('No notes')->columnSpanFull(),
            TextEntry::make('approved_change_orders_cents')->label('Approved change orders')
                ->formatStateUsing(fn (JobSite $record): string => '$'.number_format($record->approved_change_orders_cents / 100, 2).' · '.$record->approved_time_impact_days.' days')
                ->columnSpanFull(),
            ViewEntry::make('map')->label('Map')->view('filament.job-sites.map', fn (JobSite $record): array => [
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
                TextColumn::make('name')->label('Job site')->searchable()->sortable(),
                TextColumn::make('client')->label('Client')->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                TextColumn::make('supervisors.name')->label('Supervisors')->badge()->placeholder('No supervisor'),
                TextColumn::make('active_scopes_count')->label('Active scopes')->numeric(),
                TextColumn::make('active_photos_count')->label('Active photos')->numeric(),
                TextColumn::make('start_date')->label('Start')->date('m/d/Y')->placeholder('—'),
                TextColumn::make('estimated_end_date')->label('Estimated end')->date('m/d/Y')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(self::STATUSES),
                SelectFilter::make('users')->label('Supervisor')->relationship(
                    'users',
                    'name',
                    fn (Builder $query) => $query->role(LogisticsRole::Supervisor->value),
                )->searchable(),
                Filter::make('schedule')->label('Dates')->schema([
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('until')->label('To'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $sites, string $date) => $sites->whereDate('start_date', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $sites, string $date) => $sites->whereDate('start_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('changeStatus')->label('Change status')
                    ->visible(fn (JobSite $record): bool => Auth::user()->can('update', $record))
                    ->fillForm(fn (JobSite $record): array => ['status' => $record->status])
                    ->schema([
                        Select::make('status')->label('Status')->options(self::STATUSES)->required(),
                    ])
                    ->modalHeading('Change status')
                    ->modalDescription('Completing or canceling closes the job site. Confirm the status before saving.')
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
