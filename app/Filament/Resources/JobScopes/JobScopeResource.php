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

    protected static ?string $modelLabel = 'scope';

    protected static ?string $pluralModelLabel = 'scopes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public const STATUSES = [
        'pending' => 'Pending',
        'active' => 'Active',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Auth::user())->withOperationalCounts();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('job_site_id')->label('Job site')->required()->searchable()->live()
                ->options(fn (): array => JobSite::visibleTo(Auth::user())->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(fn (Set $set) => $set('supervisor_id', null))
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $site = JobSite::query()->find($value);
                    if ($site === null || ! Auth::user()->can('create', [JobScope::class, $site])) {
                        $fail('You cannot create scopes for this job site.');
                    }
                }),
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            Textarea::make('description')->label('Description')->maxLength(2000)->columnSpanFull(),
            Select::make('status')->label('Status')->required()->options(self::STATUSES)->default('pending')->live(),
            TextInput::make('progress')->label('Progress (%)')->required()->numeric()->integer()->minValue(0)->maxValue(100)->default(0)
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if ((int) $value === 100 && $get('status') !== 'completed') {
                        $fail('Progress can reach 100% only after the scope is complete.');
                    }
                }),
            Select::make('supervisor_id')->label('Person in charge')->searchable()
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
                        $fail('The person in charge must be a supervisor assigned to the job site.');
                    }
                }),
            DatePicker::make('start_date')->label('Start')->native(false),
            DatePicker::make('estimated_end_date')->label('Estimated end')->native(false)->afterOrEqual('start_date'),
            Textarea::make('notes')->label('Notes')->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('jobSite.name')->label('Job site'),
            TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            TextEntry::make('progress')->label('Progress')->suffix('%'),
            TextEntry::make('supervisor.name')->label('Person in charge')->placeholder('Unassigned'),
            TextEntry::make('start_date')->label('Start')->date('m/d/Y')->placeholder('No date'),
            TextEntry::make('estimated_end_date')->label('Estimated end')->date('m/d/Y')->placeholder('No date'),
            TextEntry::make('description')->label('Description')->placeholder('No description')->columnSpanFull(),
            TextEntry::make('notes')->label('Notes')->placeholder('No notes')->columnSpanFull(),
            TextEntry::make('approved_change_orders_cents')->label('Approved change orders')
                ->formatStateUsing(fn (JobScope $record): string => '$'.number_format($record->approved_change_orders_cents / 100, 2).' · '.$record->approved_time_impact_days.' days')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Scope')->searchable()->sortable(),
            TextColumn::make('jobSite.name')->label('Job site')->searchable(),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            ViewColumn::make('progress')->label('Progress')->view('filament.columns.progress'),
            TextColumn::make('supervisor.name')->label('Person in charge')->placeholder('Unassigned'),
            TextColumn::make('photos_count')->label('Active photos')->numeric()->sortable(),
            TextColumn::make('shifts_count')->label('Active shifts')->numeric(),
        ])->filters([
            SelectFilter::make('job_site_id')->label('Job site')
                ->options(fn (): array => JobSite::visibleTo(Auth::user())->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('status')->label('Status')->options(self::STATUSES),
            SelectFilter::make('supervisor_id')->label('Person in charge')->relationship('supervisor', 'name')->searchable(),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            Action::make('changeStatus')->label('Change status')
                ->visible(fn (JobScope $record): bool => Auth::user()->can('update', $record))
                ->fillForm(fn (JobScope $record): array => ['status' => $record->status])
                ->schema([
                    Select::make('status')->label('Status')->options(self::STATUSES)->required(),
                ])
                ->modalHeading('Change status')
                ->modalDescription('Completing this scope closes progress tracking. Confirm the status before saving.')
                ->action(function (JobScope $record, array $data): void {
                    if ($data['status'] !== 'completed' && (int) $record->progress === 100) {
                        $record->progress = 99;
                    }
                    $record->status = $data['status'];
                    $record->save();
                }),
            Action::make('duplicate')->label('Duplicate')
                ->visible(fn (JobScope $record): bool => Auth::user()->can('create', [JobScope::class, $record->jobSite]))
                ->requiresConfirmation()
                ->modalDescription('Creates a pending scope for the same job site with zero progress. Photos and shifts stay with the original scope.')
                ->action(function (JobScope $record): void {
                    $copy = $record->replicate(['photos_count', 'shifts_count']);
                    $copy->name = str($record->name.' (copia)')->limit(255, '')->toString();
                    $copy->status = 'pending';
                    $copy->progress = 0;
                    $copy->save();
                }),
            Action::make('openGallery')->label('View photos')
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
