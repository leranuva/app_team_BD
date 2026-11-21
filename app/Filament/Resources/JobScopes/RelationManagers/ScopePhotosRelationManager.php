<?php

namespace App\Filament\Resources\JobScopes\RelationManagers;

use App\Exceptions\ScopePhotoOriginalMissing;
use App\Models\ScopePhoto;
use App\Services\ScopePhotoService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ScopePhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Fotos de avance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', [ScopePhoto::class, $ownerRecord]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('uploadedBy:id,name'))
            ->columns([
                ViewColumn::make('preview')->label('Foto')->view('filament.scope-photos.thumbnail'),
                TextColumn::make('caption')->label('Descripción')->limit(60)->placeholder('Sin descripción'),
                TextColumn::make('taken_at')->label('Captura')->dateTime('M j, Y H:i', config('app.business_timezone')),
                TextColumn::make('uploadedBy.name')->label('Subida por'),
                TextColumn::make('archiveState')->label('Estado')->badge()
                    ->state(fn (ScopePhoto $record): string => $record->archived_at === null ? 'Activa' : 'Archivada')
                    ->color(fn (ScopePhoto $record): string => $record->archived_at === null ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('archiveState')->label('Estado')->options([
                    'active' => 'Activas', 'archived' => 'Archivadas', 'all' => 'Todas',
                ])->default('active')->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'active') {
                        'archived' => $query->whereNotNull('archived_at'),
                        'all' => $query,
                        default => $query->active(),
                    }),
            ])
            ->headerActions([$this->uploadAction()])
            ->recordActions([
                Action::make('viewPhoto')->label('Ver')->authorize('view')
                    ->modalHeading('Foto de avance')
                    ->modalContent(fn (ScopePhoto $record) => view('filament.scope-photos.detail', ['photo' => $record]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Cerrar'),
                Action::make('archive')->label('Archivar')->authorize('archive')
                    ->visible(fn (ScopePhoto $record) => $record->archived_at === null)
                    ->schema([self::reasonField()])
                    ->action(function (ScopePhoto $record, array $data): void {
                        app(ScopePhotoService::class)->archive($record, Auth::user(), $data['reason'] ?? null);
                        Notification::make()->success()->title('Foto archivada')->send();
                    }),
                Action::make('restore')->label('Restaurar')->authorize('restore')
                    ->visible(fn (ScopePhoto $record) => $record->archived_at !== null)
                    ->requiresConfirmation()
                    ->action(function (ScopePhoto $record): void {
                        try {
                            app(ScopePhotoService::class)->restore($record, Auth::user());
                            Notification::make()->success()->title('Foto restaurada')->send();
                        } catch (ScopePhotoOriginalMissing $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
            ])
            ->checkIfRecordIsSelectableUsing(fn (ScopePhoto $record) => $record->archived_at === null)
            ->toolbarActions([
                BulkAction::make('archiveSelected')->label('Archivar seleccionadas')
                    ->authorizeIndividualRecords('archive')->schema([self::reasonField()])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        foreach ($records as $photo) {
                            app(ScopePhotoService::class)->archive($photo, Auth::user(), $data['reason'] ?? null);
                        }

                        Notification::make()->success()->title('Fotos archivadas')->send();
                    }),
            ]);
    }

    private function uploadAction(): Action
    {
        return Action::make('uploadPhoto')->label('Añadir foto')
            ->authorize(fn () => Gate::allows('create', [ScopePhoto::class, $this->getOwnerRecord()]))
            ->databaseTransaction(false)
            ->schema([
                FileUpload::make('path')->label('Imagen')->disk('s3')->visibility('private')
                    ->image()->maxSize(10240)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->required()->preventFilePathTampering()->storeFiles(false),
                Textarea::make('caption')->label('Descripción')->maxLength(500),
                DateTimePicker::make('taken_at')->label('Capturada en Nueva York')
                    ->timezone(config('app.business_timezone'))->default(now())->seconds(false),
                Section::make('Coordenadas')->collapsed()->columns(2)->schema([
                    TextInput::make('lat')->label('Latitud')->numeric()->minValue(-90)->maxValue(90),
                    TextInput::make('lng')->label('Longitud')->numeric()->minValue(-180)->maxValue(180),
                ]),
            ])
            ->action(function (Action $action, array $data): void {
                $file = $data['path'] ?? null;
                if (! $file instanceof UploadedFile) {
                    Notification::make()->danger()->title('Selecciona un archivo para subir.')->send();
                    $action->halt();
                }

                $meta = Arr::except($data, ['path']);
                if (filled($meta['taken_at'] ?? null)) {
                    $meta['taken_at'] = CarbonImmutable::parse($meta['taken_at'], 'UTC')->toIso8601String();
                }
                try {
                    app(ScopePhotoService::class)->store($this->getOwnerRecord(), Auth::user(), $file, $meta);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title($exception->validator->errors()->first())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Foto subida')->send();
            });
    }

    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('Motivo de archivo')->maxLength(500);
    }
}
