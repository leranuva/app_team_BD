<?php

namespace App\Filament\Resources\JobScopes;

use App\Filament\Resources\JobScopes\Pages\ListJobScopes;
use App\Filament\Resources\JobScopes\Pages\ViewJobScope;
use App\Filament\Resources\JobScopes\RelationManagers\ScopePhotosRelationManager;
use App\Models\JobScope;
use App\Models\ScopePhoto;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class JobScopeResource extends Resource
{
    protected static ?string $model = JobScope::class;

    protected static ?string $modelLabel = 'alcance';

    protected static ?string $pluralModelLabel = 'alcances';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', ScopePhoto::class);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Auth::user())
            ->withCount(['photos' => fn (Builder $photos) => $photos->active()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('jobSite.name')->label('Obra'),
            TextEntry::make('status')->label('Estado')->badge(),
            TextEntry::make('progress')->label('Avance')->suffix('%'),
            TextEntry::make('supervisor.name')->label('Supervisor')->placeholder('Sin asignar'),
            TextEntry::make('description')->label('Descripción')->placeholder('Sin descripción')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Alcance')->searchable()->sortable(),
            TextColumn::make('jobSite.name')->label('Obra')->searchable(),
            TextColumn::make('progress')->label('Avance')->suffix('%'),
            TextColumn::make('photos_count')->label('Fotos activas')->numeric()->sortable(),
        ])->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ScopePhotosRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListJobScopes::route('/'), 'view' => ViewJobScope::route('/{record}')];
    }
}
